<?php

use dokuwiki\Extension\Plugin;

/**
 * Concept Graph Plugin - graph builder
 *
 * Explicit wiki links are sparse in most wikis, so on their own they make a
 * graph that falls apart into hundreds of disconnected islands. This helper
 * therefore derives most of its edges from TF-IDF cosine similarity over the
 * page texts, which connects pages that share terminology even when nothing
 * links them, and adds the explicit links on top because those express intent
 * the text cannot.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
class helper_plugin_conceptgraph extends Plugin
{
    /** @var array<string,array<string,float>> normalised tf-idf vectors keyed by page id */
    protected $vectors = [];

    /** @var array<string,int> page id => text length in characters */
    protected $lengths = [];

    /** @var array<string,string> stem => most common surface form, for readable search terms */
    protected $display = [];

    /** @var string[] ids excluded from the graph */
    protected $excluded = [];

    /**
     * Spanish and English stop words, plus the noise that shows up in wiki
     * markup once the text is stripped of syntax.
     *
     * @var string[]
     */
    protected static $stopwords = [
        // spanish
        'los', 'las', 'una', 'uno', 'unas', 'unos', 'del', 'por', 'para', 'como', 'mas', 'pero',
        'sus', 'este', 'esta', 'esto', 'ese', 'esa', 'esos', 'esas', 'aqui', 'alli', 'donde',
        'cuando', 'porque', 'pues', 'que', 'quien', 'cual', 'cuanto', 'con', 'sin', 'sobre',
        'entre', 'desde', 'hasta', 'todo', 'todos', 'toda', 'todas', 'otro', 'otra', 'otros',
        'otras', 'muy', 'tan', 'tambien', 'tampoco', 'cada', 'algo', 'nada', 'algun', 'alguna',
        'alguno', 'ningun', 'ninguna', 'tiene', 'tienen', 'tener', 'puede', 'pueden', 'hacer',
        'hace', 'hacen', 'ver', 'sino', 'solo', 'segun', 'mediante', 'mientras', 'ademas',
        'tambien', 'pues', 'cosa', 'cosas', 'forma', 'caso', 'casos', 'parte', 'partes',
        'vez', 'veces', 'tal', 'tales', 'aqui', 'ser', 'estar', 'tiene', 'tengo', 'decir',
        // english leftovers
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'was', 'were', 'are', 'you',
        'your', 'not', 'but', 'have', 'has', 'can', 'will', 'which', 'they', 'their',
    ];

    /**
     * Build (or read from cache) the whole graph.
     *
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>,stats:array<string,mixed>}
     */
    public function build()
    {
        global $conf;

        $cachefile = $this->getCacheFile();
        $signature = $this->getSignature();
        $cached = $this->readCache($cachefile, $signature);
        if ($cached !== null) {
            return $cached;
        }

        $this->excluded = $this->getExcluded();
        $this->loadPages();
        $graph = $this->assemble();
        $this->writeCache($cachefile, $signature, $graph);

        // keep the cache dir clean even if the user changed cachetime
        $this->purgeStale($cachefile);

        return $graph;
    }

    /**
     * Read a previously computed graph if it is still fresh.
     *
     * @param string $file
     * @param string $signature
     * @return array|null
     */
    protected function readCache($file, $signature)
    {
        if (!@file_exists($file)) return null;

        $age = time() - @filemtime($file);
        if ($age > $this->getConf('cachetime')) return null;

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['signature'] ?? '') !== $signature) return null;

        return [
            'nodes' => $data['nodes'],
            'edges' => $data['edges'],
            'stats' => $data['stats'],
        ];
    }

    /**
     * Persist the computed graph.
     *
     * @param string $file
     * @param string $signature
     * @param array $graph
     * @return void
     */
    protected function writeCache($file, $signature, array $graph)
    {
        io_saveFile($file, json_encode([
            'signature' => $signature,
            'generated' => time(),
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
            'stats' => $graph['stats'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove old caches of this plugin when the signature no longer matches.
     *
     * @param string $keep
     * @return void
     */
    protected function purgeStale($keep)
    {
        global $conf;

        $files = glob($conf['cachedir'] . '/conceptgraph*.json');
        foreach ($files as $f) {
            if ($f !== $keep) @unlink($f);
        }
    }

    /** @return string */
    protected function getCacheFile()
    {
        global $conf;
        return $conf['cachedir'] . '/conceptgraph.json';
    }

    /**
     * A cheap fingerprint of the wiki contents. Any page added, removed or
     * edited changes it and invalidates the cache.
     *
     * @return string
     */
    protected function getSignature()
    {
        $parts = [
            $this->getConf('neighbors'),
            $this->getConf('minweight'),
            $this->getConf('minlength'),
            $this->getConf('exclude'),
            $this->getConf('uselinks'),
        ];

        foreach ($this->getPageIndex() as $id => $info) {
            $parts[] = $id . ':' . $info['mtime'] . ':' . $info['size'];
        }

        return md5(implode('|', $parts));
    }

    /**
     * Every page of the wiki with its mtime and size, honouring ACL.
     *
     * search_allpages already collects what the signature needs, so this is
     * the single place that walks the page tree.
     *
     * @return array<string,array{mtime:int,size:int}>
     */
    protected function getPageIndex()
    {
        global $conf;

        $data = [];
        search($data, $conf['datadir'], 'search_allpages', []);

        $index = [];
        foreach ($data as $item) {
            $index[$item['id']] = [
                'mtime' => (int)($item['mtime'] ?? 0),
                'size' => (int)($item['size'] ?? 0),
            ];
        }
        ksort($index);

        return $index;
    }

    /**
     * All page ids of the wiki.
     *
     * @return string[]
     */
    protected function listPages()
    {
        return array_keys($this->getPageIndex());
    }

    /** @return string[] */
    protected function getExcluded()
    {
        $raw = trim((string)$this->getConf('exclude'));
        if ($raw === '') return [];

        $out = [];
        foreach (preg_split('/[,\s]+/', $raw) as $id) {
            $id = trim($id);
            if ($id !== '') $out[] = $id;
        }

        return $out;
    }

    /**
     * Read every eligible page and turn it into a tf-idf vector.
     *
     * @return void
     */
    protected function loadPages()
    {
        $minlength = (int)$this->getConf('minlength');
        $docs = [];

        foreach ($this->listPages() as $id) {
            if (in_array($id, $this->excluded, true)) continue;
            if (plugin_isdisabled('conceptgraph')) continue;

            $file = utf8_decodeFN(wikiFN($id));
            if (!@file_exists($file)) continue;

            $text = @file_get_contents($file);
            if ($text === false) continue;

            $tokens = $this->tokenize($text);
            if (count($tokens) === 0) continue;

            $this->lengths[$id] = strlen(utf8_strtolower($this->stripMarkup($text)));
            if ($this->lengths[$id] < $minlength) continue;

            $docs[$id] = $tokens;
        }

        if ($docs === []) {
            return;
        }

        // document frequency
        $df = [];
        foreach ($docs as $tokens) {
            foreach (array_unique($tokens) as $word) {
                $df[$word] = ($df[$word] ?? 0) + 1;
            }
        }

        $n = count($docs);
        $idf = [];
        foreach ($df as $word => $count) {
            $idf[$word] = log(($n + 1) / ($count + 1)) + 1;
        }

        foreach ($docs as $id => $tokens) {
            $tf = array_count_values($tokens);
            $vector = [];
            foreach ($tf as $word => $freq) {
                if (!isset($idf[$word])) continue;
                // sublinear tf damps pages that repeat a word for emphasis
                $vector[$word] = (1 + log($freq)) * $idf[$word];
            }
            $vector = $this->normalize($vector);
            if ($vector !== []) $this->vectors[$id] = $vector;
        }
    }

    /**
     * Turn DokuWiki markup into a bag of comparable words.
     *
     * @param string $text
     * @return string[]
     */
    protected function tokenize($text)
    {
        $text = $this->stripMarkup($text);

        // fold accents so "radios" and "radío" do not become two different words
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($folded !== false) $text = $folded;
        }
        $text = strtolower($text);
        $text = str_replace(['ñ'], ['n'], $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);

        $out = [];
        foreach (explode(' ', $text) as $word) {
            if (strlen($word) < 3) continue;
            if (in_array($word, self::$stopwords, true)) continue;
            if (ctype_digit($word)) continue;

            $stem = $this->stem($word);
            if ($stem === '') continue;

            // remember the most common surface form so search shows real words
            if (!isset($this->display[$stem])) {
                $this->display[$stem] = $word;
            }

            $out[] = $stem;
        }

        return $out;
    }

    /**
     * A deliberately small stemmer. Over-stemming merges unrelated Spanish
     * words, which would invent similarities that are not there, so this only
     * folds the endings that actually collapse the same root.
     *
     * @param string $word
     * @return string
     */
    protected function stem($word)
    {
        static $suffixes = [
            'aciones', 'acion', 'adoras', 'adores', 'ancias', 'idad',
            'mente', 'idades', 'ismo', 'ivos', 'ivas', 'ico', 'ica',
            'ando', 'iendo', 'arse', 'erse', 'irse',
        ];

        $changed = false;
        foreach ($suffixes as $suffix) {
            $len = strlen($word);
            $slen = strlen($suffix);
            if ($len - $slen >= 4 && substr($word, -$slen) === $suffix) {
                $word = substr($word, 0, $len - $slen);
                $changed = true;
                break;
            }
        }
        if (!$changed && strlen($word) > 4) {
            if (substr($word, -1) === 's') $word = substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Remove wiki syntax, urls and media references so they do not pollute
     * the term frequencies.
     *
     * @param string $text
     * @return string
     */
    protected function stripMarkup($text)
    {
        // code blocks and everything between them
        $text = preg_replace('/<code\b.*?<\/code>/s', ' ', $text);
        // media and file references
        $text = preg_replace('/\{\{.*?\}\}/s', ' ', $text);
        // external links, keeping the label
        $text = preg_replace('/\[\[(?:https?|ftp|mailto):[^\]|]*\|([^\]]*)\]\]/i', ' $1 ', $text);
        $text = preg_replace('/\[\[(?:https?|ftp|mailto):[^\]]*\]\]/i', ' ', $text);
        // remaining wiki links
        $text = preg_replace('/\[\[([^\]|]*)(?:\|[^\]]*)?\]\]/', ' $1 ', $text);
        // urls
        $text = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', ' ', $text);
        // table cells and separators
        $text = str_replace(['|', '^'], ' ', $text);
        // html
        $text = strip_tags($text);
        // remaining markup characters
        $text = preg_replace('/[*_~#;>\-+=<>"\x27()\[\]{}]+/', ' ', $text);

        return $text;
    }

    /**
     * Scale a vector to unit length.
     *
     * @param array<string,float> $vector
     * @return array<string,float>
     */
    protected function normalize(array $vector)
    {
        $sum = 0.0;
        foreach ($vector as $value) $sum += $value * $value;
        if ($sum <= 0) return [];

        $norm = sqrt($sum);
        foreach ($vector as $word => $value) {
            $vector[$word] = $value / $norm;
        }

        return $vector;
    }

    /**
     * Cosine similarity of two unit vectors.
     *
     * @param array<string,float> $a
     * @param array<string,float> $b
     * @return float
     */
    protected function cosine(array $a, array $b)
    {
        // iterate the smaller one, the rest is treated as zero
        if (count($a) > count($b)) {
            $swap = $a;
            $a = $b;
            $b = $swap;
        }

        $sum = 0.0;
        foreach ($a as $word => $value) {
            if (isset($b[$word])) $sum += $value * $b[$word];
        }

        return $sum;
    }

    /**
     * Combine similarity edges and link edges into the final graph.
     *
     * @return array
     */
    protected function assemble()
    {
        $k = (int)$this->getConf('neighbors');
        $minweight = (float)$this->getConf('minweight');
        $simweight = (float)$this->getConf('simweight');

        // PHP turns a numeric string key into an int, so a page literally called
// "2013" would leave the payload as a number and never match its own edges.
$ids = array_map('strval', array_keys($this->vectors));
        $count = count($ids);

        // collect the top-K neighbours of every page
        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $a = $ids[$i];
            $scored = [];
            for ($j = $i + 1; $j < $count; $j++) {
                $b = $ids[$j];
                $sim = $this->cosine($this->vectors[$a], $this->vectors[$b]);
                if ($sim >= $minweight) $scored[$b] = $sim;
            }
            arsort($scored);
            foreach (array_slice($scored, 0, $k, true) as $b => $sim) {
                // keep one undirected edge per pair
                $key = $a . "\0" . $b;
                $pairs[$key] = $sim;
            }
        }

        // symmetric edges: page b may rank a outside its own top-K
        $best = [];
        foreach ($pairs as $key => $sim) {
            [$a, $b] = explode("\0", $key, 2);
            $pair = ($a < $b) ? "$a\0$b" : "$b\0$a";
            if (!isset($best[$pair]) || $best[$pair] < $sim) $best[$pair] = $sim;
        }

        $degree = array_fill_keys($ids, 0);
        $edges = [];
        foreach ($best as $pair => $sim) {
            [$a, $b] = explode("\0", $pair, 2);
            // similarity is the weaker signal, so scale it well below a link
            $edges[] = [
                'source' => $a,
                'target' => $b,
                'kind' => 'sim',
                'weight' => round($simweight * $sim, 4),
                'score' => round($sim, 4),
            ];
            $degree[$a]++;
            $degree[$b]++;
        }

        $linked = 0;
        if ($this->getConf('uselinks')) {
            foreach ($this->collectLinks() as $from => $targets) {
                foreach ($targets as $to => $_) {
                    if (!isset($this->vectors[$from], $this->vectors[$to])) continue;
                    $edges[] = [
                        'source' => (string)$from,
                        'target' => (string)$to,
                        'kind' => 'link',
                        'weight' => 1.0,
                        'score' => 1.0,
                    ];
                    $degree[$from]++;
                    $degree[$to]++;
                    $linked++;
                }
            }
        }

        $nodes = [];
        foreach ($ids as $id) {
            $nodes[] = [
                'id' => (string)$id,
                'title' => $this->pageTitle($id),
                'degree' => $degree[$id],
                'length' => $this->lengths[$id] ?? 0,
                'terms' => $this->topTerms($id, 10),
            ];
        }

        $stats = $this->makeStats($nodes, $edges);

        return ['nodes' => $nodes, 'edges' => $edges, 'stats' => $stats];
    }

    /**
     * The most characteristic words of a page, as readable strings.
     *
     * Lets the browser search page content without shipping every tf-idf vector.
     *
     * @param string $id page id
     * @param int $limit how many terms to return
     * @return string[]
     */
    protected function topTerms($id, $limit)
    {
        if (!isset($this->vectors[$id])) return [];

        $scored = $this->vectors[$id];
        arsort($scored);

        $out = [];
        foreach (array_slice($scored, 0, $limit, true) as $stem => $weight) {
            $out[] = $this->display[$stem] ?? $stem;
        }

        return $out;
    }

    /**
     * Explicit wiki links between pages that made it into the graph.
     *
     * @return array<string,array<string,bool>>
     */
    protected function collectLinks()
    {
        $known = $this->vectors;
        $out = [];

        foreach ($this->listPages() as $id) {
            if (!isset($known[$id])) continue;

            $file = utf8_decodeFN(wikiFN($id));
            if (!@file_exists($file)) continue;
            $text = @file_get_contents($file);
            if ($text === false) continue;

            $out[$id] = $this->extractLinks($text, $id, $known);
        }

        return $out;
    }

    /**
     * Pull internal [[wiki links]] out of raw markup.
     *
     * @param string $text
     * @param string $from
     * @param array<string,array> $known
     * @return array<string,bool>
     */
    protected function extractLinks($text, $from, array $known)
    {
        $targets = [];

        if (preg_match_all('/\[\[([^\]\[]+)\]\]/', $text, $matches)) {
            foreach ($matches[1] as $raw) {
                $target = trim(explode('|', $raw)[0]);
                if ($target === '') continue;

                // external, interwiki and namespace-less shortcuts
                if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target)) continue;
                if (preg_match('#^(mailto|tel|callto)\s*:#i', $target)) continue;
                if (preg_match('/^[a-z][a-z0-9]*>/i', $target)) continue;

                // anchors point inside the same page
                if (strpos($target, '#') === 0) continue;

                $target = cleanID($target);
                if ($target === '' || $target === $from) continue;

                $resolved = $this->resolveTarget($target, $from, $known);
                if ($resolved !== null) $targets[$resolved] = true;
            }
        }

        return $targets;
    }

    /**
     * Resolve a link the way DokuWiki would, including namespace-relative
     * links that are written without their namespace.
     *
     * @param string $target
     * @param string $from
     * @param array<string,array> $known
     * @return string|null
     */
    protected function resolveTarget($target, $from, array $known)
    {
        if (isset($known[$target])) return $target;

        // strip an anchor and try again
        if (($hash = strpos($target, '#')) !== false) {
            $base = substr($target, 0, $hash);
            if ($base !== '' && isset($known[$base])) return $base;
        }

        // relative to the namespace of the linking page
        if (($colon = strrpos($from, ':')) !== false) {
            $ns = substr($from, 0, $colon + 1);
            if (isset($known[$ns . $target])) return $ns . $target;
        }

        // a bare name may mean any page ending in it; prefer the shortest
        $suffix = ':' . $target;
        $best = null;
        foreach ($known as $id => $_) {
            if ($id === $target) continue;
            if (substr($id, -strlen($suffix)) === $suffix) {
                if ($best === null || strlen($id) < strlen($best)) $best = $id;
            }
        }

        return $best;
    }

    /**
     * A readable title for the node label.
     *
     * @param string $id
     * @return string
     */
    protected function pageTitle($id)
    {
        $name = str_replace(':', ' / ', $id);
        $name = str_replace('_', ' ', $name);

        return trim($name);
    }

    /**
     * Summary numbers for the admin page.
     *
     * @param array $nodes
     * @param array $edges
     * @return array
     */
    protected function makeStats(array $nodes, array $edges)
    {
        $sim = 0;
        $link = 0;
        foreach ($edges as $e) {
            if ($e['kind'] === 'sim') $sim++; else $link++;
        }

        // connected components over the undirected edge set
        $adj = [];
        foreach ($nodes as $n) $adj[$n['id']] = [];
        foreach ($edges as $e) {
            $adj[$e['source']][$e['target']] = true;
            $adj[$e['target']][$e['source']] = true;
        }
        $seen = [];
        $components = 0;
        $largest = 0;
        foreach ($adj as $start => $_) {
            if (isset($seen[$start])) continue;
            $components++;
            $stack = [$start];
            $size = 0;
            while ($stack) {
                $node = array_pop($stack);
                if (isset($seen[$node])) continue;
                $seen[$node] = true;
                $size++;
                foreach ($adj[$node] as $next => $_) {
                    if (!isset($seen[$next])) $stack[] = $next;
                }
            }
            if ($size > $largest) $largest = $size;
        }

        $degrees = [];
        foreach ($nodes as $n) $degrees[] = $n['degree'];
        $isolated = 0;
        foreach ($degrees as $d) if ($d === 0) $isolated++;

        return [
            'nodes' => count($nodes),
            'edges' => count($edges),
            'simedges' => $sim,
            'linkedges' => $link,
            'components' => $components,
            'largest' => $largest,
            'isolated' => $isolated,
            'avgdegree' => $nodes ? round(array_sum($degrees) / count($nodes), 2) : 0,
        ];
    }

    /**
     * Render the graph widget.
     *
     * Shared by the ?do=conceptgraph action and the ~~conceptgraph~~ syntax
     * component so both entry points emit exactly the same DOM, and a page can
     * rely on the graph looking the same wherever it is dropped in.
     *
     * @param array{full?:bool,exiturl?:string} $opt full pins the widget over the
     *                                                 whole viewport, exiturl is
     *                                                 where the exit control goes
     * @return string
     */
    public function renderWidget($opt = [])
    {
        $full = !empty($opt['full']);
        // wl() already returns an html safe url, hsc() would double escape the
        // ampersands into &amp;amp;
        $exiturl = isset($opt['exiturl']) ? $opt['exiturl'] : wl('');

        try {
            $graph = $this->build();
        } catch (RuntimeException $e) {
            return '<div class="cg-error">' . hsc($this->getLang('title')) . ': ' .
                hsc($e->getMessage()) . '</div>';
        }

        $stats = $graph['stats'];

        $html = '<div class="conceptgraph' . ($full ? ' conceptgraph-fullscreen' : '') . '"' .
            ' id="conceptgraph-app" data-conceptgraph="1"' .
            ' data-baseurl="' . hsc(DOKU_BASE) . '"' .
            ' data-full="' . ($full ? '1' : '0') . '"' .
            ' data-exiturl="' . $exiturl . '">';

        // Controls
        $html .= '<div class="cg-controls">';
        $html .= '<input type="search" class="cg-search" autocomplete="off" ' .
            'placeholder="' . hsc($this->getLang('searchplaceholder')) . '">';
        $html .= '<button type="button" class="cg-surprise">' . hsc($this->getLang('surprise')) . '</button>';
        // in overlay mode the close control replaces the fullscreen toggle: the
        // graph already covers the viewport, so the only useful action is out
        if ($full) {
            $html .= '<a class="cg-exit" href="' . $exiturl . '" rel="nofollow">' .
                hsc($this->getLang('exitscreen')) . '</a>';
        } else {
            $html .= '<button type="button" class="cg-fullscreen">' .
                hsc($this->getLang('fullscreen')) . '</button>';
        }
        $html .= '<button type="button" class="cg-reset">' . hsc($this->getLang('reset')) . '</button>';
        $html .= '</div>';

        // Canvas stage
        $html .= '<div class="cg-stage">';
        $html .= '<canvas class="cg-canvas"></canvas>';
        $html .= '<div class="cg-tooltip" hidden></div>';
        $html .= '<aside class="cg-path" hidden></aside>';
        $html .= '</div>';

        // Legend
        $html .= '<div class="cg-legend">';
        $html .= '<span><i class="cg-key cg-key-link"></i>' . hsc($this->getLang('legendlink')) . '</span>';
        $html .= '<span><i class="cg-key cg-key-sim"></i>' . hsc($this->getLang('legendsim')) . '</span>';
        $html .= '<span><i class="cg-key cg-key-path"></i>' . hsc($this->getLang('legendpath')) . '</span>';
        $html .= '</div>';

        // Stats
        $html .= '<p class="cg-stats">' .
            hsc($stats['nodes']) . ' ' . hsc($this->getLang('nodes')) . ' &middot; ' .
            hsc($stats['edges']) . ' ' . hsc($this->getLang('edges')) . ' (' .
            hsc($stats['simedges']) . ' ' . hsc($this->getLang('simedges')) . ', ' .
            hsc($stats['linkedges']) . ' ' . hsc($this->getLang('linkedges')) . ') &middot; ' .
            hsc($stats['components']) . ' ' . hsc($this->getLang('components')) .
            '</p>';
        $html .= '<p class="cg-stats">' . hsc($this->getLang('hint')) . '</p>';

        // Graph payload, hex encoded so it can never break out of the script tag
        $json = json_encode([
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
            'stats' => $stats,
            'wikiurl' => DOKU_BASE . 'doku.php?id=',
            'lang' => [
                'surprise' => $this->getLang('serendipity'),
                'pathfound' => $this->getLang('pathfound'),
                'pathnone' => $this->getLang('pathnone'),
                'nolinks' => $this->getLang('nolinks'),
                'open' => $this->getLang('open'),
                'edges' => $this->getLang('edges'),
                'title' => $this->getLang('title'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '<div class="cg-error">' . hsc(json_last_error_msg()) . '</div>';
        }

        $html .= '<script type="application/json" class="cg-data">' . bin2hex($json) . '</script>';
        $html .= '</div>';

        return $html;
    }

    /** Drop the cache so the next request recomputes. */
    public function purge()
    {
        global $conf;
        @unlink($conf['cachedir'] . '/conceptgraph.json');
    }

    /**
     * Base URL used to build links back into the wiki.
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return DOKU_BASE;
    }
}
