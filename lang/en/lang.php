<?php

/**
 * English language file for the conceptgraph plugin
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

$lang['title']           = 'Concept graph';
$lang['nodes']           = 'pages';
$lang['edges']           = 'edges';
$lang['simedges']        = 'by similarity';
$lang['linkedges']       = 'by wiki link';
$lang['components']      = 'clusters';
$lang['searchplaceholder'] = 'Search pages…';
$lang['serendipity']     = 'Serendipity';
$lang['surprise']        = 'Surprise me';
$lang['pathfound']       = 'These two are connected by';
$lang['pathnone']        = 'Nothing connects those two, try again.';
$lang['nolinks']         = 'No neighbours';
$lang['open']            = 'double click to open';
$lang['reset']           = 'Reset view';
$lang['fullscreen']     = 'Fullscreen';
$lang['exitscreen']     = 'Exit fullscreen';
$lang['hint']            = 'Click to select, double click to open, drag to move, scroll to zoom.';
$lang['legendlink']      = 'wiki link';
$lang['legendsim']       = 'textual similarity';
$lang['legendpath']      = 'found path';
$lang['adminheading']    = 'Concept graph';
$lang['neighbors']       = 'Neighbours per page';
$lang['neighbors_help']  = 'How many similar pages each page is linked to. Higher makes a denser, noisier graph.';
$lang['minweight']       = 'Minimum similarity';
$lang['minweight_help']  = 'Pairs below this cosine value are dropped. Raise it to remove weak connections.';
$lang['minlength']       = 'Minimum text length';
$lang['minlength_help']  = 'Pages with less text than this are ignored, which removes stub pages.';
$lang['exclude']         = 'Excluded pages';
$lang['exclude_help']    = 'Comma separated list of page ids left out of the graph, such as navigation sidebars.';
$lang['uselinks']        = 'Include wiki links';
$lang['uselinks_help']   = 'Also draw an edge for every explicit [[wiki link]].';
$lang['simweight']       = 'Similarity line weight';
$lang['simweight_help']  = 'How thick similarity lines are drawn relative to wiki links.';
$lang['cachetime']       = 'Cache lifetime';
$lang['cachetime_help']  = 'Seconds before the graph is recalculated. Any page edit also forces a recalculation.';
$lang['rebuild']         = 'Rebuild now';
$lang['rebuilt']         = 'Graph cache cleared, it will be rebuilt on the next view.';
$lang['regenerate']      = 'Need to regenerate?';
$lang['saved']         = 'Settings saved';
$lang['locked']        = 'local.php is not writable, edit it by hand.';
