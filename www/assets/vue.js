/*
 * Petit moteur d'affichage du configurateur (sans dépendance).
 * Le gabarit (<template id="tpl">) utilise :
 *   {{a.b}}                 valeur (texte ou attribut)
 *   <sc-if value="{{x}}">   bloc affiché si x est vrai
 *   <sc-for list="{{l}}" as="it">  bloc répété pour chaque élément
 *   onClick="{{f}}" / onChange="{{f}}"  événements (onChange = à chaque saisie)
 * Le composant expose state, setState() et renderVals() ; chaque setState redessine la page
 * en gardant le champ en cours de saisie, la position du curseur et les défilements.
 */
(function () {
  'use strict';

  function get(path, scope) {
    path = path.trim();
    if (path === 'true') return true;
    if (path === 'false') return false;
    var o = scope;
    var parts = path.split('.');
    for (var i = 0; i < parts.length; i++) {
      if (o == null) return '';
      o = o[parts[i]];
    }
    return o;
  }

  function interp(s, scope) {
    return s.replace(/\{\{([^}]*)\}\}/g, function (m, p) {
      var r = get(p, scope);
      return (typeof r === 'function' || r == null) ? '' : String(r);
    });
  }

  function single(s) {
    var m = s.match(/^\s*\{\{([^}]*)\}\}\s*$/);
    return m ? m[1] : null;
  }

  function walk(node, scope, out) {
    var kids = node.childNodes;
    for (var i = 0; i < kids.length; i++) {
      var ch = kids[i];
      if (ch.nodeType === 3) { out.appendChild(document.createTextNode(interp(ch.textContent, scope))); continue; }
      if (ch.nodeType !== 1) continue;
      var tag = ch.localName;
      if (tag === 'sc-for') {
        var arr = get(single(ch.getAttribute('list')) || '', scope) || [];
        var as = ch.getAttribute('as');
        for (var j = 0; j < arr.length; j++) {
          var sc = Object.create(scope);
          sc[as] = arr[j];
          walk(ch, sc, out);
        }
        continue;
      }
      if (tag === 'sc-if') {
        if (get(single(ch.getAttribute('value')) || 'false', scope)) walk(ch, scope, out);
        continue;
      }
      var el = document.createElementNS(ch.namespaceURI, tag);
      for (var a = 0; a < ch.attributes.length; a++) {
        var at = ch.attributes[a];
        var name = at.name;
        if (name.indexOf('hint-') === 0) continue;
        if (name.slice(0, 2) === 'on' && name.length > 2) {
          var fn = get(single(at.value) || '', scope);
          if (typeof fn === 'function') {
            var ev = name.slice(2).toLowerCase();
            if (ev === 'change' && !(tag === 'input' && (ch.getAttribute('type') === 'file'))) ev = 'input';
            el.addEventListener(ev, fn);
          }
          continue;
        }
        var v = interp(at.value, scope);
        if (name === 'value' && (tag === 'input' || tag === 'textarea')) { el.value = v; el.setAttribute('value', v); continue; }
        if (name === 'checked') { if (v === 'true') el.checked = true; continue; }
        if (name === 'disabled') { if (v === 'true') el.setAttribute('disabled', ''); continue; }
        el.setAttribute(name, v);
      }
      walk(ch.content || ch, scope, el);
      out.appendChild(el);
    }
  }

  window.mountComponent = function (Component, root, tpl) {
    var c = new Component({});
    var pending = false, composing = false;
    // saisie avec accent en cours (touche morte, clavier iPhone) : on attend la fin pour redessiner
    root.addEventListener('compositionstart', function () { composing = true; });
    root.addEventListener('compositionend', function () { composing = false; if (pending) render(); });
    function render() {
      if (composing) return;
      pending = false;
      // garder le champ actif, le curseur et les défilements
      var act = document.activeElement, key = act && act.getAttribute && act.getAttribute('data-k');
      var selS = null, selE = null;
      try { if (key) { selS = act.selectionStart; selE = act.selectionEnd; } } catch (e) { /* champ sans sélection */ }
      var scrolls = {};
      root.querySelectorAll('[data-scroll]').forEach(function (n) { scrolls[n.getAttribute('data-scroll')] = [n.scrollLeft, n.scrollTop]; });
      var frag = document.createDocumentFragment();
      walk(tpl.content, c.renderVals(), frag);
      root.replaceChildren(frag);
      root.querySelectorAll('[data-scroll]').forEach(function (n) {
        var s = scrolls[n.getAttribute('data-scroll')];
        if (s) { n.scrollLeft = s[0]; n.scrollTop = s[1]; }
      });
      if (key) {
        var n = root.querySelector('[data-k="' + key + '"]');
        if (n) { n.focus({ preventScroll: true }); try { if (selS != null) n.setSelectionRange(selS, selE); } catch (e) { /* type sans curseur */ } }
      }
      if (c.afterRender) c.afterRender(root);
    }
    c.setState = function (patch) {
      this.state = Object.assign({}, this.state, typeof patch === 'function' ? patch(this.state) : patch);
      if (!pending) { pending = true; Promise.resolve().then(render); }
    };
    render();
    return c;
  };
})();
