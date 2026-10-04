/**
 * Concept Graph Plugin - force directed graph on canvas
 *
 * Written without a graph library on purpose: the wiki is on a small box and
 * pulling vis.js from a CDN would add an external dependency that silently
 * breaks the page when it is unreachable.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
(function () {
    'use strict';

    var PALETTE = {
        node: '#7aa2f7',
        nodeDim: '#3b4261',
        label: '#c0caf5',
        labelDim: '#565f89',
        link: '#e0af68',
        sim: '#7dcfff',
        path: '#f7768e',
        hover: '#bb9af7'
    };

    function ConceptGraph(root) {
        this.root = root;
        this.canvas = root.querySelector('.cg-canvas');
        if (!this.canvas) throw new Error('graph container has no .cg-canvas');
        this.ctx = this.canvas.getContext('2d');
        if (!this.ctx) throw new Error('canvas 2d context unavailable');
        this.tooltip = root.querySelector('.cg-tooltip');
        this.pathBox = root.querySelector('.cg-path');
        this.searchInput = root.querySelector('.cg-search');

        var raw = root.querySelector('.cg-data');
        if (!raw) throw new Error('missing .cg-data payload');

        // PHP sends the payload hex encoded so it cannot break out of the script tag
        var hex = (raw.textContent || '').replace(/\s+/g, '');
        var bytes = new Uint8Array(Math.floor(hex.length / 2));
        for (var i = 0; i < bytes.length; i++) {
            bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
        }
        this.data = JSON.parse(new TextDecoder('utf-8').decode(bytes));

        this.strings = this.data.lang || {};
        this.baseUrl = this.data.wikiurl || root.getAttribute('data-baseurl') || '/';
        this.exitUrl = root.getAttribute('data-exiturl') || '';

        this.nodes = this.data.nodes || [];
        this.edges = this.data.edges || [];

        this.index = {};
        this.adjacency = {};
        var self = this;
        this.nodes.forEach(function (node, i) {
            node.i = i;
            node.x = Math.cos(i) * 200;
            node.y = Math.sin(i) * 200;
            node.vx = 0;
            node.vy = 0;
            self.index[node.id] = i;
            self.adjacency[node.id] = [];
        });

        var maxDegree = 1;
        this.nodes.forEach(function (n) { if (n.degree > maxDegree) maxDegree = n.degree; });
        this.maxDegree = maxDegree;

        this.edges.forEach(function (edge) {
            var a = self.index[edge.source];
            var b = self.index[edge.target];
            edge.a = a;
            edge.b = b;
            if (a === undefined || b === undefined) return;
            self.adjacency[edge.source].push({ to: edge.target, edge: edge });
            self.adjacency[edge.target].push({ to: edge.source, edge: edge });
        });

        this.scale = 1;
        this.offsetX = 0;
        this.offsetY = 0;
        this.alpha = 1;
        this.hover = null;
        this.selected = null;
        this.pathEdges = [];
        this.pathNodes = {};
        this.matchSet = null;
        this.dragging = null;
        this.panning = false;
        this.pointerMoved = 0;
        this.pointerStart = null;
        this.running = false;

        this.bindEvents();
        this.resize();
        this.heat();
    }

    ConceptGraph.prototype.heat = function () {
        this.alpha = 1;
        if (!this.running) {
            this.running = true;
            var self = this;
            requestAnimationFrame(function step() {
                self.tick();
                if (self.alpha > 0.001 || self.hover || self.pathEdges.length) {
                    requestAnimationFrame(step);
                } else {
                    self.running = false;
                }
            });
        }
    };

    /* ---------------------------------------------------------------- layout */

    ConceptGraph.prototype.tick = function () {
        var nodes = this.nodes;
        var n = nodes.length;
        var i, j, a, b;

        var repulsion = 900;
        var restLength = 60;
        var stiffness = 0.02;

        // pairwise repulsion; O(n^2) is fine for a few hundred pages
        for (i = 0; i < n; i++) {
            a = nodes[i];
            for (j = i + 1; j < n; j++) {
                b = nodes[j];
                var dx = b.x - a.x;
                var dy = b.y - a.y;
                var distSq = dx * dx + dy * dy;
                if (distSq < 0.01) {
                    // exactly on top of each other: nudge apart deterministically
                    dx = (i - j) * 0.1 + 0.05;
                    dy = (j - i) * 0.1 + 0.05;
                    distSq = dx * dx + dy * dy;
                }
                var dist = Math.sqrt(distSq);
                var force = (repulsion / distSq) * this.alpha;
                var ux = dx / dist;
                var uy = dy / dist;
                a.vx -= ux * force;
                a.vy -= uy * force;
                b.vx += ux * force;
                b.vy += uy * force;
            }
        }

        // springs along the edges
        this.edges.forEach(function (edge) {
            var na = nodes[edge.a];
            var nb = nodes[edge.b];
            if (!na || !nb) return;
            var dx = nb.x - na.x;
            var dy = nb.y - na.y;
            var dist = Math.sqrt(dx * dx + dy * dy) || 0.01;
            var force = (dist - restLength) * stiffness * edge.weight * this.alpha * 10;
            var ux = dx / dist;
            var uy = dy / dist;
            na.vx += ux * force;
            na.vy += uy * force;
            nb.vx -= ux * force;
            nb.vy -= uy * force;
        }, this);

        // gravity keeps disconnected pieces from drifting away forever
        for (i = 0; i < n; i++) {
            a = nodes[i];
            a.vx -= a.x * 0.002 * this.alpha;
            a.vy -= a.y * 0.002 * this.alpha;

            if (a === this.dragging) {
                a.vx = 0;
                a.vy = 0;
                continue;
            }
            a.vx *= 0.82;
            a.vy *= 0.82;
            var speed = Math.abs(a.vx) + Math.abs(a.vy);
            if (speed > 40) {
                a.vx = a.vx / speed * 40;
                a.vy = a.vy / speed * 40;
            }
            a.x += a.vx;
            a.y += a.vy;
        }

        this.alpha *= 0.985;
        this.draw();
    };

    /* ----------------------------------------------------------------- draw */

    ConceptGraph.prototype.draw = function () {
        var ctx = this.ctx;
        var w = this.width;
        var h = this.height;
        var self = this;

        ctx.setTransform(this.dpr || 1, 0, 0, this.dpr || 1, 0, 0);
        ctx.clearRect(0, 0, w, h);
        ctx.save();
        ctx.translate(this.offsetX, this.offsetY);
        ctx.scale(this.scale, this.scale);

        var pathSet = this.pathEdges;
        var focused = this.hover || this.selected;
        var neighbors = null;
        if (focused) {
            neighbors = {};
            neighbors[focused.id] = true;
            this.adjacency[focused.id].forEach(function (link) {
                neighbors[link.to] = true;
            });
        }

        // edges
        this.edges.forEach(function (edge) {
            var na = self.nodes[edge.a];
            var nb = self.nodes[edge.b];
            if (!na || !nb) return;

            var onPath = pathSet.indexOf(edge) !== -1;
            var dim = false;
            if (neighbors && !(neighbors[edge.source] && neighbors[edge.target])) {
                if (!onPath) dim = true;
            }
            if (this.matchSet && !onPath) {
                if (!(this.matchSet[edge.source] && this.matchSet[edge.target])) dim = true;
            }

            ctx.beginPath();
            ctx.moveTo(na.x, na.y);
            ctx.lineTo(nb.x, nb.y);
            if (onPath) {
                ctx.strokeStyle = PALETTE.path;
                ctx.lineWidth = 3.2 / this.scale;
                ctx.globalAlpha = 1;
            } else if (edge.kind === 'link') {
                ctx.strokeStyle = PALETTE.link;
                ctx.lineWidth = 1.9 / this.scale;
                ctx.globalAlpha = dim ? 0.07 : 0.85;
            } else {
                ctx.strokeStyle = PALETTE.sim;
                ctx.lineWidth = Math.max(0.7, edge.weight * 4) / this.scale;
                ctx.globalAlpha = dim ? 0.05 : Math.min(0.6, 0.16 + edge.weight);
            }
            ctx.stroke();
        }, this);
        ctx.globalAlpha = 1;

        // nodes
        this.nodes.forEach(function (node) {
            var radius = 3.4 + Math.sqrt(node.degree / self.maxDegree) * 6.5;
            var dim = false;
            if (neighbors && !neighbors[node.id]) dim = true;
            if (this.matchSet && !this.matchSet[node.id] && !this.pathNodes[node.id]) dim = true;

            var onPath = !!this.pathNodes[node.id];
            var isFocus = this.hover === node || this.selected === node;

            ctx.beginPath();
            ctx.arc(node.x, node.y, radius, 0, Math.PI * 2);
            if (onPath) {
                ctx.fillStyle = PALETTE.path;
                ctx.globalAlpha = 1;
            } else if (isFocus) {
                ctx.fillStyle = PALETTE.hover;
                ctx.globalAlpha = 1;
            } else {
                ctx.fillStyle = PALETTE.node;
                ctx.globalAlpha = dim ? 0.18 : 0.95;
            }
            ctx.fill();
            ctx.globalAlpha = 1;

            if (isFocus || onPath || (neighbors && neighbors[node.id] && !dim)) {
                ctx.beginPath();
                ctx.arc(node.x, node.y, radius + 3 / this.scale, 0, Math.PI * 2);
                ctx.strokeStyle = isFocus ? PALETTE.hover : PALETTE.path;
                ctx.lineWidth = 1.6 / this.scale;
                ctx.globalAlpha = 0.75;
                ctx.stroke();
                ctx.globalAlpha = 1;
            }
        }, this);

        // labels: only where they stay readable
        var showAll = this.scale > 0.62;
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        this.nodes.forEach(function (node) {
            var dim = false;
            if (neighbors && !neighbors[node.id]) dim = true;
            if (this.matchSet && !this.matchSet[node.id] && !this.pathNodes[node.id]) dim = true;
            var isFocus = this.hover === node || this.selected === node;
            var onPath = !!this.pathNodes[node.id];

            var important = isFocus || onPath || node.degree >= this.maxDegree * 0.45;
            if (!important && !showAll) return;

            var radius = 3.4 + Math.sqrt(node.degree / this.maxDegree) * 6.5;
            var label = node.title.length > 26 ? node.title.slice(0, 25) + '…' : node.title;
            ctx.fillStyle = isFocus ? PALETTE.hover : (dim ? PALETTE.labelDim : PALETTE.label);
            ctx.globalAlpha = dim ? 0.3 : (isFocus || onPath ? 1 : 0.82);
            ctx.fillText(label, node.x, node.y + radius + 3 / this.scale);
        }, this);

        ctx.restore();
        ctx.globalAlpha = 1;
    };

    /* ----------------------------------------------------------- coordinates */

    ConceptGraph.prototype.toWorld = function (clientX, clientY) {
        var rect = this.canvas.getBoundingClientRect();
        var x = (clientX - rect.left - this.offsetX) / this.scale;
        var y = (clientY - rect.top - this.offsetY) / this.scale;
        return { x: x, y: y };
    };

    ConceptGraph.prototype.nodeAt = function (clientX, clientY) {
        var pos = this.toWorld(clientX, clientY);
        var best = null;
        var bestDist = Infinity;
        // generous radius so small nodes are still clickable
        var tolerance = 10 / this.scale;
        this.nodes.forEach(function (node) {
            var dx = node.x - pos.x;
            var dy = node.y - pos.y;
            var dist = Math.sqrt(dx * dx + dy * dy);
            var radius = 3.4 + Math.sqrt(node.degree / this.maxDegree) * 6.5;
            if (dist < radius + tolerance && dist < bestDist) {
                best = node;
                bestDist = dist;
            }
        }, this);
        return best;
    };

    /* -------------------------------------------------------------- serendipity */

    /** Breadth first search returning the shortest path between two ids. */
    ConceptGraph.prototype.findPath = function (startId, goalId) {
        if (startId === goalId) return null;
        var visited = {};
        var queue = [startId];
        visited[startId] = null;
        var found = false;

        while (queue.length && !found) {
            var current = queue.shift();
            var links = this.adjacency[current] || [];
            for (var i = 0; i < links.length; i++) {
                var next = links[i].to;
                if (visited[next] !== undefined) continue;
                visited[next] = current;
                if (next === goalId) { found = true; break; }
                queue.push(next);
            }
        }

        if (visited[goalId] === undefined) return null;

        var path = [goalId];
        var walk = goalId;
        while (visited[walk] !== null) {
            walk = visited[walk];
            path.unshift(walk);
        }
        return path;
    };

    /** Distance in hops, capped, used to pick an interesting second node. */
    ConceptGraph.prototype.hopDistance = function (startId, limit) {
        var seen = {};
        seen[startId] = 0;
        var queue = [startId];
        var found = 0;
        while (queue.length) {
            var current = queue.shift();
            if (seen[current] >= limit) continue;
            var links = this.adjacency[current] || [];
            for (var i = 0; i < links.length; i++) {
                var next = links[i].to;
                if (seen[next] !== undefined) continue;
                seen[next] = seen[current] + 1;
                found++;
                if (seen[next] === limit) return found;
                queue.push(next);
            }
        }
        return found;
    };

    /**
     * Pick two pages that are far apart, then show what links them. Picking
     * two random nodes usually lands on neighbours, which is boring.
     */
    ConceptGraph.prototype.surprise = function () {
        var pool = this.nodes.filter(function (n) { return n.degree > 0; });
        if (pool.length < 2) return;

        var start = pool[Math.floor(Math.random() * pool.length)];
        var reachable = this.hopDistance(start.id, 3);
        var candidates = [];

        for (var attempt = 0; attempt < 12 && candidates.length === 0; attempt++) {
            candidates = pool.filter(function (n) {
                if (n.id === start.id) return false;
                // avoid immediate neighbours, they make for a dull reveal
                if ((this.adjacency[start.id] || []).some(function (l) { return l.to === n.id; })) return false;
                var path = this.findPath(start.id, n.id);
                return path && path.length >= 3;
            }, this);
        }

        if (candidates.length === 0) {
            this.showPath(this.strings.pathnone || 'No path found');
            return;
        }

        var goal = candidates[Math.floor(Math.random() * candidates.length)];
        var path = this.findPath(start.id, goal.id);

        this.pathNodes = {};
        this.pathEdges = [];
        var self = this;
        path.forEach(function (id) { self.pathNodes[id] = true; });
        for (var i = 0; i < path.length - 1; i++) {
            (function (from, to) {
                self.edges.forEach(function (edge) {
                    if ((edge.source === from && edge.target === to) ||
                        (edge.source === to && edge.target === from)) {
                        self.pathEdges.push(edge);
                    }
                });
            })(path[i], path[i + 1]);
        }

        this.centreOn(path);
        this.renderPath(path);
        this.heat();
    };

    ConceptGraph.prototype.centreOn = function (path) {
        var xs = 0;
        var ys = 0;
        path.forEach(function (id) {
            var i = this.index[id];
            if (i === undefined) return;
            xs += this.nodes[i].x;
            ys += this.nodes[i].y;
        }, this);
        xs /= path.length;
        ys /= path.length;
        this.offsetX = this.width / 2 - xs * this.scale;
        this.offsetY = this.height / 2 - ys * this.scale;
    };

    ConceptGraph.prototype.renderPath = function (path) {
        var self = this;
        var html = '<div class="cg-path-head">' +
            (this.strings.pathfound || 'Path') + '</div><ol>';
        path.forEach(function (id, i) {
            var node = self.nodes[self.index[id]];
            var label = node ? node.title : id;
            html += '<li><a href="' + self.baseUrl + encodeURIComponent(id.replace(/ /g, '_')) + '">' +
                escapeHtml(label) + '</a></li>';
        });
        html += '</ol>';
        this.pathBox.innerHTML = html;
        this.pathBox.hidden = false;
    };

    ConceptGraph.prototype.showPath = function (message) {
        this.pathNodes = {};
        this.pathEdges = [];
        this.pathBox.innerHTML = '<div class="cg-path-head">' + escapeHtml(message) + '</div>';
        this.pathBox.hidden = false;
        this.heat();
    };

    /* ---------------------------------------------------------------- search */

    /** Fold accents and case so "radio" finds "rádio" and vice versa. */
    function fold(value) {
        var text = String(value).toLowerCase();
        if (text.normalize) text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return text;
    }

    /**
     * Rank pages by where the term appears: title first, then id, then content
     * terms. Returns the number of matching pages and stores them in matchSet.
     */
    ConceptGraph.prototype.applySearch = function (term) {
        term = fold(term || '').trim();
        if (term.length < 2) {
            this.matchSet = null;
            this.heat();
            return 0;
        }

        var words = term.split(/\s+/).filter(function (w) { return w.length >= 2; });
        if (!words.length) {
            this.matchSet = null;
            this.heat();
            return 0;
        }

        var matches = {};
        var count = 0;

        this.nodes.forEach(function (node) {
            var title = fold(node.title);
            var id = fold(node.id);
            var terms = fold((node.terms || []).join(' '));
            if (!terms) return;

            var score = 0;
            var hitAll = true;

            words.forEach(function (word) {
                var points = 0;
                if (title.indexOf(word) !== -1) points += 10;
                if (id.indexOf(word) !== -1) points += 6;
                if (terms.indexOf(word) !== -1) points += 2;
                if (points === 0) hitAll = false;
                score += points;
            });

            // every word must appear somewhere, otherwise the match is noise
            if (!hitAll || score === 0) return;
            matches[node.id] = true;
            count++;
        });

        this.matchSet = count ? matches : null;
        this.heat();
        return count;
    };

    /* ----------------------------------------------------------- fullscreen */

    /**
     * True while the widget is pinned over the whole viewport, either because
     * the page asked for it or because the button was pressed.
     *
     * @return {boolean}
     */
    ConceptGraph.prototype.isFullscreen = function () {
        return this.root.classList.contains('conceptgraph-fullscreen');
    };

    /**
     * Toggle the viewport-sized overlay and ask the browser for real
     * fullscreen on top of it. Native fullscreen can be refused (no user
     * gesture, iframe policy), so the overlay works either way.
     */
    ConceptGraph.prototype.toggleFullscreen = function () {
        if (this.isFullscreen()) {
            this.leaveFullscreen();
        } else {
            this.root.classList.add('conceptgraph-fullscreen');
            if (this.root.requestFullscreen) {
                var self = this;
                this.root.requestFullscreen().catch(function () { /* overlay is enough */ });
            }
        }

        this.resize();
        this.heat();
    };

    /**
     * Leave both the overlay and native fullscreen.
     *
     * When the page was rendered in full mode there is no in page graph to fall
     * back to, so the only way out is the page the widget came from.
     */
    ConceptGraph.prototype.leaveFullscreen = function () {
        var wasFull = this.isFullscreen();
        this.root.classList.remove('conceptgraph-fullscreen');

        if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(function () { /* already out */ });
        }

        if (wasFull && this.root.getAttribute('data-full') === '1') {
            if (this.exitUrl) {
                window.location.href = this.exitUrl;
            }
            return;
        }

        this.resize();
        this.heat();
    };

    /* ---------------------------------------------------------------- events */

    ConceptGraph.prototype.bindEvents = function () {
        var self = this;
        var canvas = this.canvas;

        window.addEventListener('resize', function () {
            self.resize();
            self.heat();
        });

        canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            var rect = canvas.getBoundingClientRect();
            var mx = e.clientX - rect.left;
            var my = e.clientY - rect.top;
            var factor = e.deltaY < 0 ? 1.12 : 1 / 1.12;
            var next = Math.max(0.15, Math.min(6, self.scale * factor));
            // keep the point under the cursor fixed while zooming
            self.offsetX = mx - (mx - self.offsetX) * (next / self.scale);
            self.offsetY = my - (my - self.offsetY) * (next / self.scale);
            self.scale = next;
            self.heat();
        }, { passive: false });

        canvas.addEventListener('mousedown', function (e) {
            self.pointerMoved = 0;
            self.pointerStart = { x: e.clientX, y: e.clientY };
            var node = self.nodeAt(e.clientX, e.clientY);
            if (node) {
                self.dragging = node;
            } else {
                self.panning = true;
            }
        });

        window.addEventListener('mousemove', function (e) {
            if (self.dragging) {
                var pos = self.toWorld(e.clientX, e.clientY);
                self.dragging.x = pos.x;
                self.dragging.y = pos.y;
                self.heat();
                return;
            }
            if (self.panning && self.pointerStart) {
                self.offsetX += e.clientX - self.pointerStart.x;
                self.offsetY += e.clientY - self.pointerStart.y;
                self.pointerStart = { x: e.clientX, y: e.clientY };
                self.heat();
                return;
            }
            if (self.pointerStart) {
                self.pointerMoved += Math.abs(e.clientX - self.pointerStart.x) +
                                     Math.abs(e.clientY - self.pointerStart.y);
            }

            var node = self.nodeAt(e.clientX, e.clientY);
            if (node !== self.hover) {
                self.hover = node;
                canvas.style.cursor = node ? 'pointer' : 'grab';
                self.showTooltip(node, e);
                self.heat();
            } else if (node) {
                self.showTooltip(node, e);
            }
        });

        window.addEventListener('mouseup', function (e) {
            var wasDragging = self.dragging;
            var moved = self.pointerMoved;
            self.dragging = null;
            self.panning = false;
            self.pointerStart = null;

            // a click, not the end of a pan or a node drag
            if (wasDragging && moved < 4) {
                self.select(wasDragging, e);
            }
            self.heat();
        });

        canvas.addEventListener('mouseleave', function () {
            self.hover = null;
            self.tooltip.hidden = true;
            self.heat();
        });

        // single click selects, double click opens the page
        canvas.addEventListener('dblclick', function (e) {
            var node = self.nodeAt(e.clientX, e.clientY);
            if (!node) return;
            e.preventDefault();
            window.location.href = self.baseUrl + encodeURIComponent(node.id.replace(/ /g, '_'));
        });

        this.searchInput.addEventListener('input', function () {
            self.applySearch(self.searchInput.value);
        });

        this.root.querySelector('.cg-surprise').addEventListener('click', function () {
            self.surprise();
        });

        var fsButton = this.root.querySelector('.cg-fullscreen');
        if (fsButton) {
            fsButton.addEventListener('click', function () {
                self.toggleFullscreen();
            });
        }

        // pressing Esc leaves native fullscreen: drop the overlay too so the
        // wiki is usable again without hunting for a close button
        document.addEventListener('fullscreenchange', function () {
            if (!document.fullscreenElement) {
                self.root.classList.remove('conceptgraph-fullscreen');
            }
            self.resize();
            self.heat();
        });

        // Esc also has to work when the browser refused native fullscreen, so
        // the overlay cannot be a trap with no keyboard way out
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !self.isFullscreen()) return;
            if (document.fullscreenElement) return; // the event above covers it
            e.preventDefault();
            self.leaveFullscreen();
        });

        this.root.querySelector('.cg-reset').addEventListener('click', function () {
            self.selected = null;
            self.hover = null;
            self.matchSet = null;
            self.pathNodes = {};
            self.pathEdges = [];
            self.pathBox.hidden = true;
            self.searchInput.value = '';
            self.scale = 1;
            self.offsetX = 0;
            self.offsetY = 0;
            self.alpha = 1;
            self.heat();
        });
    };

    ConceptGraph.prototype.select = function (node, e) {
        this.selected = node;
        this.tooltip.hidden = true;

        var links = this.adjacency[node.id] || [];
        var html = '<div class="cg-tip-title">' + escapeHtml(node.title) + '</div>';
        html += '<div class="cg-tip-meta">' + escapeHtml(this.strings.open || 'Open') + '</div>';
        if (links.length) {
            html += '<ul class="cg-tip-list">';
            links.slice(0, 8).forEach(function (link) {
                var other = this.nodes[this.index[link.to]];
                if (!other) return;
                html += '<li><span class="cg-tip-kind cg-tip-' + link.edge.kind + '"></span>' +
                    escapeHtml(other.title) + '</li>';
            }, this);
            html += '</ul>';
            if (links.length > 8) {
                html += '<div class="cg-tip-more">+' + (links.length - 8) + '</div>';
            }
        }
        this.tooltip.innerHTML = html;
        this.tooltip.hidden = false;

        var rect = this.canvas.getBoundingClientRect();
        var tw = 240;
        var left = Math.min(Math.max(8, e.clientX - rect.left + 14), rect.width - tw - 8);
        var top = Math.min(Math.max(8, e.clientY - rect.top + 14), rect.height - 120);
        this.tooltip.style.left = left + 'px';
        this.tooltip.style.top = top + 'px';

        // single click selects, double click navigates
    };

    ConceptGraph.prototype.showTooltip = function (node, e) {
        if (!node) {
            this.tooltip.hidden = true;
            return;
        }
        this.tooltip.innerHTML = '<div class="cg-tip-title">' + escapeHtml(node.title) + '</div>' +
            '<div class="cg-tip-meta">' + node.degree + ' ' +
            escapeHtml(this.strings.edges || 'edges') + '</div>';
        this.tooltip.hidden = false;
        var rect = this.canvas.getBoundingClientRect();
        this.tooltip.style.left = Math.min(
            Math.max(8, e.clientX - rect.left + 14), rect.width - 250) + 'px';
        this.tooltip.style.top = Math.min(
            Math.max(8, e.clientY - rect.top + 14), rect.height - 60) + 'px';
    };

    ConceptGraph.prototype.resize = function () {
        var rect = this.canvas.parentNode.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;
        this.width = Math.max(320, rect.width);
        this.height = Math.max(320, rect.height);
        this.canvas.width = Math.round(this.width * dpr);
        this.canvas.height = Math.round(this.height * dpr);
        this.canvas.style.width = this.width + 'px';
        this.canvas.style.height = this.height + 'px';
        this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        this.dpr = dpr;
    };

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function init() {
        // Match on the data attribute, not on .conceptgraph: DokuWiki's page
        // menu renders links as class="action conceptgraph" and those are not
        // graph containers, they have no canvas.
        var roots = document.querySelectorAll('[data-conceptgraph]');
        Array.prototype.forEach.call(roots, function (root) {
            if (root.getAttribute('data-conceptgraph-ready')) return;
            root.setAttribute('data-conceptgraph-ready', '1');
            try {
                new ConceptGraph(root);
            } catch (err) {
                root.insertAdjacentHTML('beforeend',
                    '<div class="cg-error">Could not start the graph: ' + escapeHtml(err.message) + '</div>');
                if (window.console) console.error('conceptgraph', err);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.ConceptGraph = ConceptGraph;
})();
