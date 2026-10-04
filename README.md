# Concept Graph

Plugin para DokuWiki que construye un **grafo conceptual de tu wiki** y lo muestra
interactivo, con un buscador de páginas y un botón de serendipia.

La idea nació de un problema concreto: en un wiki los enlaces `[[así]]` son
escasos, y si solo dibujas esos enlaces el grafo se rompe en cientos de islas
sueltas sin relación entre sí. Este plugin saca la mayor parte de sus aristas de
la **similitud coseno TF-IDF** entre los textos de las páginas, que conecta páginas
que comparten terminología aunque nadie las enlace, y encima suma los enlaces
explícitos porque esos expresan una intención que el texto no puede.

## Requisitos

- DokuWiki **2020-07-29** ("Hogfather") o superior (usa la API de extensiones)
- PHP 7.2 o superior
- Extensión `iconv` recomendada: sin ella las palabras con acentos y eñes se
  comparan peor, aunque el grafo se sigue construyendo

No necesita librerías externas: el dibujo se hace con un `<canvas>` propio, sin
depender de vis-network ni de ningún CDN.

## Instalación

1. Descarga el plugin o clona el repositorio:
   ```bash
   git clone https://github.com/morezane/dokuwiki-plugin-conceptgraph.git \
     lib/plugins/conceptgraph
   ```
2. Instálalo desde el **Administrador de plugins** de DokuWiki, o descomprime el
   ZIP en `lib/plugins/conceptgraph/`.
3. Listo. No hay nada que configurar para que funcione.

## Uso

### Insertar el grafo en una página

Escribe en cualquier página:

```
~~conceptgraph~~
```

Y para que el grafo ocupe toda la pantalla:

```
~~conceptgraph full~~
```

La forma `full` muestra el grafo sobre el viewport completo, con un enlace para
salir de ese modo que devuelve a la misma página con el grafo en línea, así que
la página nunca queda en un callejón sin salida.

### Abrir el grafo directamente

El plugin también registra una acción propia:

```
https://tu-wiki.example/doku.php?do=conceptgraph
```

Los estilos y el JavaScript solo se cargan en las páginas que realmente llevan el
grafo, porque el encabezado se envía antes de analizar el texto de la página: no
tiene sentido bajar 35 kB de JavaScript en todas las páginas del wiki.

### En la página de administración

En **Administrador → Ajustes → Grafo conceptual** (icono de red) puedes cambiar los
parámetros y ver las estadísticas del grafo. También hay un botón para forzar la
reconstrucción.

## Configuración

Los valores por defecto están en `conf/default.php` y se ajustan desde la página de
administración, que los guarda en `local.php`.

| Ajuste | Por defecto | Qué hace |
|---|---|---|
| `neighbors` | `7` | Cuántas páginas similares se conectan con cada página (top-K). Más alto = grafo más denso y ruidoso. |
| `minweight` | `0.03` | Los pares por debajo de este valor coseno se descartan. Súbelo para quitar conexiones débiles. |
| `minlength` | `200` | Se ignoran las páginas con menos texto que esto, lo que elimina las páginas vacías. |
| `exclude` | `sidebar,start,playground` | Ids de página separados por comas que no entran al grafo, como las barras de navegación. |
| `uselinks` | `1` | Dibuja además una arista por cada enlace `[[wiki]]` explícito. |
| `simweight` | `0.45` | Qué tan gruesas se dibujan las líneas de similitud frente a los enlaces wiki. |
| `cachetime` | `3600` | Segundos antes de recalcular el grafo. Editar cualquier página también lo fuerza. |

El grafo se cachea en `cachedir/conceptgraph.json`. La caché lleva una firma
(`md5`) que incluye los ajustes y el nombre, tamaño y fecha de modificación de
cada página, así que añadir, borrar o editar cualquier página la invalida sola.

## Qué hay dentro

| Archivo | Función |
|---|---|
| `helper.php` | Construye el grafo: tokeniza, calcula vectores TF-IDF, arma las aristas, arma la caché y genera el marcado del widget. |
| `action.php` | Registra la acción `?do=conceptgraph` e inyecta CSS y JavaScript solo cuando hacen falta. |
| `admin.php` | Página de administración con los ajustes, validación de valores y estadísticas. |
| `syntax/conceptgraph.php` | Componente de sintaxis para `~~conceptgraph~~` y `~~conceptgraph full~~`. |
| `script.js` | Renderizador sobre `<canvas>`, simulador de fuerzas, buscador y serendipia. |
| `style.css` | Estilos del widget, los controles, la leyenda y el modo pantalla completa. |
| `lang/es/`, `lang/en/` | Textos de la interfaz. |

### Cómo se calcula la similitud

1. Se lee cada página elegible y se le quita el marcado de DokuWiki.
2. Se pliegan los acentos y las eñes, se pasa todo a minúsculas y se separa en
   palabras: se descartan las de menos de 3 caracteres, las numéricas y las
   palabras vacías.
3. Cada palabra se reduce a una raíz con un lematizador **deliberadamente
   pequeño**. Un lematizador agresivo en español fusiona palabras que no tienen
   nada que ver e inventa similitudes que no existen, así que este solo pliega las
   terminaciones que de verdad colapsan la misma raíz.
4. Se calcula el TF-IDF de cada página y se normaliza el vector.
5. Se comparan todos los pares con coseno, se descartan los que quedan bajo
   `minweight` y de cada página se quedan sus `neighbors` mejores.

## Detalles de implementación

- **Las aristas son simétricas y sin duplicar.** Cada par se guarda una sola vez,
  quedándose con la mayor de las dos puntuaciones, porque la página B puede tener a
  A entre sus mejores vecinas mientras A no tiene a B entre las suyas.
- **Las claves se fuerzan a string.** PHP convierte una clave numérica de un array
  en `int`, así que una página llamada `2013` dejaría su id como número y nunca
  coincidiría con sus propias aristas.
- **El JSON va codificado en hexadecimal** dentro del `<script>`, para que ningún
  contenido de una página pueda cerrar la etiqueta y romper el HTML.
- **El marcado se escapa una sola vez.** `wl()` ya devuelve una URL segura para
  HTML, y aplicarle `hsc()` encima escaparía los `&` dos veces.

## Licencia

GPL 2.0 o posterior. Ver el archivo [LICENSE](LICENSE).