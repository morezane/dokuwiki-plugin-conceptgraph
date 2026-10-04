<?php

/**
 * Concept Graph Plugin - action
 *
 * Exposes the graph as a DokuWiki action (?do=conceptgraph) and injects its assets.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Concept graph action
 */
class action_plugin_conceptgraph extends ActionPlugin
{
    public const ACTION = 'conceptgraph';
    public const PAGEID = 'conceptgraph';
    public const PLUGIN_DIR = 'lib/plugins/conceptgraph/';

    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        // Stop the router from looking for a core class named "Conceptgraph"
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handlePreprocess');
        // This action class renders our content instead of the default warning
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handleContent');
        // Our CSS/JS are not auto-included for action plugins
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleAssets');
    }

    /**
     * Claim the action name so the router hands rendering over to us.
     *
     * @param Event $event
     * @return bool
     */
    public function handlePreprocess(Event $event)
    {
        if ($event->data !== self::ACTION) return true;
        $event->preventDefault();
        return false;
    }

    /**
     * Add the plugin stylesheet and script.
     *
     * Only the pages that actually carry the graph get them: the widget is
     * emitted by a syntax component, and the header has to be sent before the
     * page is parsed, so the raw wiki text is what we have to go by. Shipping
     * 35 kB of javascript to every one of the wiki pages otherwise is waste on
     * a box this small.
     *
     * @param Event $event
     * @return void
     */
    public function handleAssets(Event $event)
    {
        global $ACT;
        global $ID;

        if (!is_array($event->data)) return;
        if (!$this->isGraphPage($ID, $ACT)) return;

        $base = DOKU_BASE . self::PLUGIN_DIR;
        $seed = @filemtime(DOKU_PLUGIN . 'conceptgraph/script.js');
        if (!$seed) $seed = time();

        $event->data['link'][] = [
            'rel' => 'stylesheet',
            'href' => $base . 'style.css?' . $seed,
        ];
        $event->data['script'][] = [
            'src' => $base . 'script.js?' . $seed,
            'defer' => 'defer',
        ];
    }

    /**
     * Does this request need the graph assets?
     *
     * @param string $id
     * @param string $act
     * @return bool
     */
    protected function isGraphPage($id, $act)
    {
        if ($act === self::ACTION) return true;
        if (!$id) return false;

        return strpos(rawWiki($id), '~~conceptgraph') !== false;
    }

    /**
     * Emit the graph markup.
     *
     * The default action of TPL_ACT_UNKNOWN is to print "Failed to handle
     * action", so the default has to be cancelled or that message shows up
     * underneath our own output.
     *
     * @param Event $event
     * @return void
     */
    public function handleContent(Event $event)
    {
        global $ID;
        global $INFO;
        global $ACT;
        global $TOC;

        if ($event->data !== self::ACTION) return;

        $event->preventDefault();

        // The template renders $ID, give the view a sensible identity
        $ID = self::PAGEID;
        $ACT = self::ACTION;
        $INFO['id'] = self::PAGEID;
        $INFO['exists'] = false;
        $INFO['writable'] = false;
        $INFO['secret'] = false;
        $TOC = '';

        echo $this->render();
    }

    /**
     * Build the full graph widget markup.
     *
     * @return string
     */
    protected function render()
    {
        global $INPUT;

        $helper = $this->loadHelper('conceptgraph');

        return $helper->renderWidget([
            'full' => $INPUT->bool('full'),
            'exiturl' => wl('', 'do=' . self::ACTION),
        ]);
    }
}