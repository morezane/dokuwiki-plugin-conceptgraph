<?php

/**
 * Spanish language file for the conceptgraph plugin
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

$lang['title']           = 'Grafo conceptual';
$lang['nodes']           = 'páginas';
$lang['edges']           = 'conexiones';
$lang['simedges']        = 'por similitud';
$lang['linkedges']       = 'por enlace';
$lang['components']      = 'grupos';
$lang['searchplaceholder'] = 'Buscar páginas…';
$lang['serendipity']     = 'Serendipia';
$lang['surprise']        = 'Sorpréndeme';
$lang['pathfound']       = 'Estos dos están conectados por';
$lang['pathnone']        = 'No hay ningún camino entre esos dos, otra vez.';
$lang['nolinks']         = 'Sin vecinos';
$lang['open']            = 'doble clic para abrir';
$lang['reset']           = 'Restablecer vista';
$lang['fullscreen']     = 'Pantalla completa';
$lang['exitscreen']     = 'Salir de pantalla completa';
$lang['hint']            = 'Clic para seleccionar, doble clic para abrir, arrastrar para mover, rueda para zoom.';
$lang['legendlink']      = 'enlace wiki';
$lang['legendsim']       = 'similitud de texto';
$lang['legendpath']      = 'camino encontrado';
$lang['adminheading']    = 'Grafo conceptual';
$lang['neighbors']       = 'Vecinos por página';
$lang['neighbors_help']  = 'Cuántas páginas similares se conectan con cada página. Más alto = grafo más denso y ruidoso.';
$lang['minweight']       = 'Similitud mínima';
$lang['minweight_help']  = 'Los pares por debajo de este valor coseno se descartan. Súbelo para quitar conexiones débiles.';
$lang['minlength']       = 'Longitud mínima de texto';
$lang['minlength_help']  = 'Se ignoran las páginas con menos texto que esto, lo que elimina las páginas vacías.';
$lang['exclude']         = 'Páginas excluidas';
$lang['exclude_help']    = 'Lista de ids de página separados por comas que no entran al grafo, como las barras de navegación.';
$lang['uselinks']        = 'Incluir enlaces wiki';
$lang['uselinks_help']   = 'Dibujar además una arista por cada [[enlace wiki]] explícito.';
$lang['simweight']       = 'Grosor de las líneas de similitud';
$lang['simweight_help']  = 'Qué tan gruesas se dibujan las líneas de similitud frente a los enlaces wiki.';
$lang['cachetime']       = 'Duración de la caché';
$lang['cachetime_help']  = 'Segundos antes de recalcular el grafo. Editar cualquier página también lo fuerza.';
$lang['rebuild']         = 'Reconstruir ahora';
$lang['rebuilt']         = 'Caché del grafo borrada, se reconstruirá la próxima vez que se vea.';
$lang['regenerate']      = '¿Necesitas regenerarlo?';
$lang['saved']         = 'Ajustes guardados';
$lang['locked']        = 'local.php no es escribible, edítalo a mano.';
