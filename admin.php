<?php

/**
 * Concept Graph Plugin - Admin page
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;

/**
 * Settings and statistics for the concept graph
 */
class admin_plugin_conceptgraph extends AdminPlugin
{
    /** name used for the block inside local.php */
    protected const BLOCK = 'conceptgraph';

    /** @inheritdoc */
    public function forAdminOnly()
    {
        return true;
    }

    /** @inheritdoc */
    public function getMenuSort()
    {
        return 70;
    }

    /** @inheritdoc */
    public function getMenuIcon()
    {
        return 'icon_network.svg';
    }

    /** @inheritdoc */
    public function handle()
    {
        global $INPUT;

        if (!$INPUT->bool('rebuild') && !$INPUT->bool('save')) return;
        if (!checkSecurityToken()) return;

        $helper = $this->loadHelper('conceptgraph');

        if ($INPUT->bool('save')) {
            $clean = $this->collectSettings($INPUT);
            if (empty($clean)) return;

            try {
                $this->writeSettings($clean);
                $helper->purge();
                msg($this->getLang('saved'), 1);
            } catch (RuntimeException $e) {
                msg($e->getMessage(), -1);
            }
            return;
        }

        $helper->purge();
        msg($this->getLang('rebuilt'), 1);
    }

    /**
     * Validate and clamp whatever came in from the form.
     *
     * @param \dokuwiki\Input $INPUT
     * @return array<string,int|float|string> sanitised settings, empty if nothing valid
     */
    protected function collectSettings($INPUT)
    {
        $out = [];

        // Neighbours per page. Clamped rather than rejected: a number input
        // lets someone type 99999, and silently keeping the old value looks
        // like the page is broken.
        $out['neighbors'] = self::clamp((int)$INPUT->int('neighbors'), 1, 40);

        // Minimum cosine similarity, accepts a comma as decimal separator
        $out['minweight'] = round(
            self::clamp((float)str_replace(',', '.', (string)$INPUT->str('minweight')), 0, 1),
            4
        );

        // Minimum text length in characters
        $out['minlength'] = self::clamp((int)$INPUT->int('minlength'), 0, 20000);

        // Visual similarity line weight
        $out['simweight'] = round(
            self::clamp((float)str_replace(',', '.', (string)$INPUT->str('simweight')), 0, 3),
            4
        );

        // Cache lifetime
        $out['cachetime'] = self::clamp((int)$INPUT->int('cachetime'), 0, 86400);

        // Excluded page ids: keep only the characters a page id can contain
        $exclude = trim((string)$INPUT->str('exclude'));
        $exclude = preg_replace('/[^\p{L}\p{N}_\-:\.\s,]/u', '', $exclude);
        $out['exclude'] = mb_substr(trim($exclude), 0, 1000);

        // Checkbox: unchecked means the key is absent, which means off
        $out['uselinks'] = $INPUT->bool('uselinks') ? 1 : 0;

        return $out;
    }

    /**
     * Force a number into an allowed range.
     *
     * @param int|float $value
     * @param int|float $min
     * @param int|float $max
     * @return int|float
     */
    protected static function clamp($value, $min, $max)
    {
        if (!is_numeric($value)) return $min;
        return max($min, min($max, $value));
    }

    /**
     * Rewrite only our own block in conf/local.php, keeping everything else.
     *
     * @param array<string,int|float|string> $settings
     * @throws RuntimeException
     * @return void
     */
    protected function writeSettings(array $settings)
    {
        $file = DOKU_CONF . 'local.php';

        if (!is_writable($file)) {
            throw new RuntimeException(hsc($this->getLang('locked')));
        }

        $current = @file_get_contents($file);
        if ($current === false) {
            throw new RuntimeException('could not read ' . hsc(basename($file)));
        }

        // drop any previous block we wrote, then append a fresh one
        $pattern = '/^\$conf\[\'plugin\'\]\[\'' . preg_quote(self::BLOCK, '/') . '\'\]\[[^\]]+\].*\R?/m';
        $kept = preg_replace($pattern, '', $current);

        $lines = [];
        foreach ($settings as $key => $value) {
            $value = is_int($value) ? (string)$value : (string)$value;
            $lines[] = "\$conf['plugin']['" . self::BLOCK . "']['" . $key . "'] = " . $value . ';';
        }

        $new = rtrim($kept) . "\n" . implode("\n", $lines) . "\n";

        if (!io_saveFile($file, $new)) {
            throw new RuntimeException('could not write ' . hsc(basename($file)));
        }
    }

    /** @inheritdoc */
    public function html()
    {
        echo $this->locale_xhtml('adminheading');

        $helper = $this->loadHelper('conceptgraph');
        $graph = $helper->build();
        $stats = $graph['stats'];

        echo '<div class="conceptgraph-stats">';
        echo '<p>';
        foreach (['nodes', 'edges', 'simedges', 'linkedges', 'components'] as $key) {
            $label = $this->getLang($key);
            if ($label === $key) $label = ucfirst(str_replace('_', ' ', $key));
            echo hsc($label) . ': <b>' . hsc((string)$stats[$key]) . '</b> &nbsp; ';
        }
        echo '</p>';
        echo '<p class="conceptgraph-note">' .
            hsc('cluster más grande: ' . $stats['largest']) . ' &middot; ' .
            hsc('aisladas: ' . $stats['isolated']) . ' &middot; ' .
            hsc('grado medio: ' . $stats['avgdegree']) .
            '</p>';
        echo '</div>';

        $this->settingsForm();

        echo '<form method="post" action="">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="' . self::BLOCK . '">';
        echo '<input type="hidden" name="rebuild" value="1">';
        p_csrf_token();
        echo '<button type="submit" class="button">' . hsc($this->getLang('rebuild')) . '</button>';
        echo '</form>';
    }

    /**
     * Render the configuration fields.
     *
     * @return void
     */
    protected function settingsForm()
    {
        echo '<form method="post" action="" class="conceptgraph-settings">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="' . self::BLOCK . '">';
        echo '<input type="hidden" name="save" value="1">';
        p_csrf_token();

        echo '<table class="settings">';

        $numbers = [
            'neighbors' => [1, 40, 1],
            'minweight' => [0, 1, 0.01],
            'minlength' => [0, 20000, 1],
            'simweight' => [0, 3, 0.05],
            'cachetime' => [0, 86400, 1],
        ];

        foreach ($numbers as $key => [$min, $max, $step]) {
            echo '<tr><th scope="row">' . hsc($this->getLang($key)) . '</th><td>';
            echo '<input type="number" name="' . hsc($key) . '" class="edit"' .
                ' min="' . $min . '" max="' . $max . '" step="' . $step . '"' .
                ' value="' . hsc((string)$this->getConf($key)) . '">';
            echo '<div class="help">' . hsc($this->getLang($key . '_help')) . '</div></td></tr>';
        }

        echo '<tr><th scope="row">' . hsc($this->getLang('exclude')) . '</th><td>';
        echo '<input type="text" name="exclude" class="edit" ' .
            'value="' . hsc((string)$this->getConf('exclude')) . '">';
        echo '<div class="help">' . hsc($this->getLang('exclude_help')) . '</div></td></tr>';

        echo '<tr><th scope="row">' . hsc($this->getLang('uselinks')) . '</th><td>';
        echo '<label><input type="checkbox" name="uselinks" value="1"' .
            ($this->getConf('uselinks') ? ' checked="checked"' : '') . '> ' .
            hsc($this->getLang('uselinks_help')) . '</label></td></tr>';

        echo '</table>';
        echo '<button type="submit" class="button">' . hsc($this->getLang('btn_save')) . '</button>';
        echo '</form>';
    }
}