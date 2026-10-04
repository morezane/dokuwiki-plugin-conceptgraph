<?php

/**
 * Default settings for the conceptgraph plugin
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

$conf['neighbors']  = 7;      // how many similar pages to link per page (top-K)
$conf['minweight']  = 0.03;   // tuned: with stop words removed this is what keeps all 173 pages in one component
$conf['minlength']  = 200;    // ignore pages with less text than this (chars)
$conf['exclude']    = 'sidebar,start,playground'; // page ids left out of the graph
$conf['uselinks']   = 1;      // also draw edges for explicit [[wiki links]]
$conf['simweight']  = 0.45;   // visual weight of a similarity edge
$conf['cachetime']  = 3600;   // seconds before the graph is recomputed
