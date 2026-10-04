<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * Concept Graph Plugin - syntax component
 *
 * Drops the graph into any page: ~~conceptgraph~~ for an inline widget,
 * ~~conceptgraph full~~ to pin it over the whole viewport. The page grafo
 * uses the second form so it shows nothing but the graph.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
class syntax_plugin_conceptgraph_conceptgraph extends SyntaxPlugin
{
    /** GET parameter that turns the full page mode off again */
    public const NOCOVER = 'cgcover';

    /** @inheritdoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritdoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritdoc */
    public function getSort()
    {
        return 100;
    }

    /** @inheritdoc */
    public function connectTo($mode)
    {
        // the mode name carries plugin and component, that is what the parser
        // hands back to Handler::plugin() to load this class again
        $this->Lexer->addSpecialPattern(
            '~~conceptgraph(?:\s+[a-z]+)*~~',
            $mode,
            'plugin_conceptgraph_conceptgraph'
        );
    }

    /**
     * @inheritdoc
     * @return array
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $ID;

        // "~~conceptgraph full~~" -> ['conceptgraph', 'full']
        $flags = preg_split('/\s+/', trim($match, " \t\n\r\0\x0B~"), -1, PREG_SPLIT_NO_EMPTY);
        array_shift($flags); // drop "conceptgraph"

        // leaving the overlay lands on the same page with the graph inline
        // again, so the page is never a dead end
        $full = in_array('full', $flags, true) && !isset($_GET[self::NOCOVER]);

        $helper = $this->loadHelper('conceptgraph');

        return [
            $state,
            $helper->renderWidget([
                'full' => $full,
                // wl() already returns an html safe url, hsc() would double escape
                // the ampersands into &amp;amp;. The id goes in as the first
                // argument, passing it inside the params duplicates id=start
                'exiturl' => wl($ID, [self::NOCOVER => 0]),
            ]),
        ];
    }

    /**
     * @inheritdoc
     * @return bool
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;

        $renderer->doc .= $data[1];
        return true;
    }
}
