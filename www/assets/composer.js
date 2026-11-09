/* Configurateur du carnet d'étiquettes (etiquettes.copycolor.fr).
   Affiché par assets/vue.js à partir du gabarit <template id="tpl"> de composer.html.
   Les prix sont recalculés par le serveur à la commande (app/src/Catalog.php) : mêmes règles qu'ici. */
var STORE_KEY = 'cc-etiquettes-v1';
var PACK_SETS = {
  senior: [['thermo', 3], ['mini', 2]],
  rentree: [['thermo', 1], ['mini', 1], ['chaussure', 1], ['ronde', 1], ['stylo', 1]],
  colo: [['thermo', 2], ['mini', 1], ['ronde', 1], ['chaussure', 1]],
  creche: [['thermo', 3], ['ronde', 1], ['chaussure', 1]]
};
// Tarifs : valeurs par défaut, remplacées par celles publiées par l'atelier (/api/tarifs)
var TARIFS = {
  packs: [
    { id: 'senior', label: 'Pack senior', cents: 1900, content: { thermo: 3, mini: 2 } },
    { id: 'rentree', label: 'Pack rentrée', cents: 2200, content: { thermo: 1, mini: 1, chaussure: 1, ronde: 1, stylo: 1 } },
    { id: 'colo', label: 'Pack colo & sport', cents: 2400, content: { thermo: 2, mini: 1, ronde: 1, chaussure: 1 } },
    { id: 'creche', label: 'Pack crèche', cents: 2900, content: { thermo: 3, ronde: 1, chaussure: 1 } }
  ],
  unit: { thermo: 300, adhesive: 200 }
};
function eur(c) { return (c % 100 ? (c / 100).toFixed(2).replace('.', ',') : String(c / 100)) + ' €'; }
function packPlanches(id) {
  var pk = TARIFS.packs.filter(function (p) { return p.id === id; })[0];
  var set = pk ? Object.keys(pk.content).map(function (f) { return [f, pk.content[f]]; }) : (PACK_SETS[id] || PACK_SETS.rentree);
  return set.map(function (x, i) { return { id: i + 1, product: x[0], qty: x[1], own: null }; });
}
function loadSaved() {
  try { var d = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); return d && d.v === 1 ? d : null; } catch (e) { return null; }
}

class Component {
  constructor(props) {
    this.props = props;
    // Design commun à tout le carnet ; chaque planche peut avoir son design perso (own) qui prend le dessus
    var pack = (location.search.match(/[?&]pack=([a-z0-9-]+)/) || [])[1];
    var saved = loadSaved();
    var planches = pack ? packPlanches(pack) : (saved && saved.planches && saved.planches.length ? saved.planches : packPlanches('rentree'));
    var common = Object.assign({ name: '', line2: '', font: 'fredoka', color: 'lime', picto: 'i-licorne', auto: true, customUrl: '', imageId: '', pattern: 'uni', textColor: 'auto' },
      saved ? saved.common : {});
    if (pack) history.replaceState(null, '', '/composer');
    this._pack = pack || null;   // composition définitive au chargement des tarifs (packs modifiables dans l'atelier)
    this.state = {
      common: common, planches: planches,
      sel: planches[0].id, nextId: Math.max.apply(null, planches.map(function (p) { return p.id; })) + 1, scope: 'all',
      // Fenêtre d'accueil : à la première ouverture, on demande prénom et nom
      welcome: !common.name, draft: { name: '', line2: '' },
      uploading: false, uploadError: '',
      checkout: false, sending: false, orderError: '', fieldErrors: {}, done: null,
      customer: (saved && saved.customer) || { name: '', email: '', phone: '' }, consent: false,
      // Mode atelier (Ctrl + Alt + E, ou 5 appuis rapides sur « Mon carnet ») : composition libre, code vérifié par le serveur
      staffCode: (function () { try { return sessionStorage.getItem('cc-atelier') || ''; } catch (e) { return ''; } })(),
      staffAsk: false, staffInput: '', staffErr: '', staffPrice: ''
    };
    var self = this;
    if (typeof document !== 'undefined') {
      document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.altKey && (e.key === 'e' || e.key === 'E' || e.code === 'KeyE')) {
          e.preventDefault();
          self.setState({ staffAsk: true, staffInput: '', staffErr: '' });
        }
      });
    }
  }
  staffLogin() {
    const code = (this.state.staffInput || '').trim();
    if (!code) return;
    fetch('/api/atelier', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: code }) })
      .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
      .then(({ ok, d }) => {
        if (!ok) { this.setState({ staffErr: d.message || 'Code incorrect.' }); return; }
        try { sessionStorage.setItem('cc-atelier', code); } catch (e) { /* rien */ }
        this.setState({ staffCode: code, staffAsk: false, staffInput: '', staffErr: '' });
      })
      .catch(() => this.setState({ staffErr: 'Connexion perdue.' }));
  }
  // Carnet gardé dans le navigateur : on le retrouve en revenant sur la page
  loadTarifs() {
    fetch('/api/tarifs').then((r) => (r.ok ? r.json() : null)).then((d) => {
      if (!(d && Array.isArray(d.packs) && d.packs.length && d.unit)) return;
      TARIFS = d;
      const patch = { tarifsAt: Date.now() };
      if (this._pack && TARIFS.packs.some((p) => p.id === this._pack)) {
        const pl = packPlanches(this._pack);
        Object.assign(patch, { planches: pl, sel: pl[0].id, nextId: pl.length + 1 });
      }
      this._pack = null;
      this.setState(patch);
    }).catch(() => { /* prix par défaut */ });
  }
  save() {
    var s = this.state;
    try { localStorage.setItem(STORE_KEY, JSON.stringify({ v: 1, common: s.common, planches: s.planches, customer: s.customer })); } catch (e) { /* navigation privée */ }
  }
  // Modifie le design : pour tout le carnet (scope « all ») ou pour la planche sélectionnée seulement
  update(patch) {
    const s = this.state;
    if (s.scope === 'all') {
      this.setState({ common: Object.assign({}, s.common, patch) });
    } else {
      this.setState({ planches: s.planches.map((p) => p.id === s.sel ? Object.assign({}, p, { own: Object.assign({}, p.own || {}, patch) }) : p) });
    }
  }
  afterRender() { if (!this.state.done) this.save(); }
  setProduct(id) {
    this.setState({ planches: this.state.planches.map((p) => p.id === this.state.sel ? Object.assign({}, p, { product: id }) : p) });
  }
  renderVals() {
    const s = this.state;
    // Formats en mm : exemples à caler sur les vrais gabarits de découpe
    const PRODUCTS = {
      thermo: { label: 'Thermocollantes', w: 38, h: 12, r: 2, thermo: true },
      mini: { label: 'Mini', w: 25, h: 11, r: 2 },
      chaussure: { label: 'Chaussures', w: 28, h: 44, r: 0, foot: true },
      ronde: { label: 'Rondes', w: 37, h: 37, r: 0, round: true },
      stylo: { label: 'Stylo', w: 48, h: 8, r: 1.5, inline: true }
    };
    const SW = 100, SH = 210, M = 5, G = 2.5;
    // Règles de production : planche 220 × 100, zone utile 207 × 94 ; adhésives fond perdu 1,5 (écart 3), thermo écart 5
    const fitIn = (w, h, t) => { const aw = t ? 207 : 204, ah = t ? 94 : 91, g = t ? 5 : 3; return [Math.max(0, Math.floor((aw + g) / (w + g))), Math.max(0, Math.floor((ah + g) / (h + g)))]; };
    // Essaie les deux sens et garde celui qui met le plus d'étiquettes sur la planche
    const layout = (p) => {
      const [c1, r1] = fitIn(p.w, p.h, p.thermo), [c2, r2] = fitIn(p.h, p.w, p.thermo);
      if (c2 * r2 > c1 * r1) return { cols: c2, rows: r2, n: c2 * r2, rot: true, cw: p.h, ch: p.w };
      return { cols: c1, rows: r1, n: c1 * r1, rot: false, cw: p.w, ch: p.h };
    };
    const FONTS = {
      fredoka: { css: "'Fredoka', sans-serif", weight: 600, italic: false, sample: 'Arrondie' },
      pacifico: { css: "'Pacifico', cursive", weight: 400, italic: false, sample: 'Manuscrite' },
      archivo: { css: "'Archivo', sans-serif", weight: 800, italic: true, sample: 'Sportive' },
      patrick: { css: "'Patrick Hand', cursive", weight: 400, italic: false, sample: 'Écolier' }
    };
    const COLORS = {
      lime: { bg: '#B1D36B', fg: '#2D2D31', ink: '#5E7A1F', label: 'Vert' },
      pink: { bg: '#EE5F90', fg: '#2D2D31', ink: '#B83C78', label: 'Rose' },
      teal: { bg: '#3E9C8C', fg: '#FFFFFF', ink: '#3E9C8C', label: 'Vert d\u2019eau' },
      orange: { bg: '#F2994A', fg: '#2D2D31', ink: '#B4570E', label: 'Orange' },
      red: { bg: '#C62F35', fg: '#FFFFFF', ink: '#C62F35', label: 'Rouge' },
      violet: { bg: '#6E52C2', fg: '#FFFFFF', ink: '#6E52C2', label: 'Violet' },
      blue: { bg: '#2F6FB5', fg: '#FFFFFF', ink: '#2F6FB5', label: 'Bleu' },
      ink: { bg: '#2D2D31', fg: '#FFFFFF', ink: '#2D2D31', label: 'Noir' }
    };
    const FOOT_PARTS = [[[0.6063, 0.0921], [0.5972, 0.072], [0.5767, 0.0562], [0.5451, 0.045], [0.5083, 0.0415], [0.4718, 0.046], [0.4413, 0.058], [0.4213, 0.0756], [0.4149, 0.0961], [0.4241, 0.1176], [0.4463, 0.1343], [0.4784, 0.145], [0.5153, 0.148], [0.5516, 0.143], [0.5816, 0.1306], [0.6008, 0.1127]], [[0.4147, 0.123], [0.4062, 0.1059], [0.3866, 0.092], [0.3589, 0.0832], [0.3273, 0.0811], [0.2967, 0.0858], [0.2716, 0.0967], [0.2565, 0.1111], [0.2519, 0.1286], [0.2596, 0.1458], [0.2786, 0.16], [0.3058, 0.1691], [0.3393, 0.1716], [0.37, 0.1669], [0.3951, 0.156], [0.4108, 0.1406]], [[0.9074, 0.1227], [0.8915, 0.0841], [0.8549, 0.0526], [0.8032, 0.0328], [0.7404, 0.0281], [0.6834, 0.0398], [0.6374, 0.0652], [0.6094, 0.1005], [0.6036, 0.1404], [0.6208, 0.1787], [0.6586, 0.2097], [0.7111, 0.2286], [0.7703, 0.2325], [0.8272, 0.2208], [0.8732, 0.1954], [0.9001, 0.1625]], [[0.2694, 0.1757], [0.2462, 0.1484], [0.1968, 0.1382], [0.1472, 0.1505], [0.1277, 0.1787], [0.1497, 0.2063], [0.2003, 0.2172], [0.2511, 0.2042]], [[0.17, 0.2423], [0.1493, 0.2194], [0.107, 0.2113], [0.0652, 0.2223], [0.0497, 0.2465], [0.0694, 0.2697], [0.1129, 0.2783], [0.1556, 0.2667]], [[0.1847, 0.3301], [0.1396, 0.3624], [0.1057, 0.4055], [0.0852, 0.4642], [0.0799, 0.5247], [0.1372, 0.7375], [0.1768, 0.8229], [0.2123, 0.8719], [0.2539, 0.9079], [0.2995, 0.9321], [0.3657, 0.9529], [0.4484, 0.9678], [0.5215, 0.9724], [0.5946, 0.9686], [0.6722, 0.9556], [0.7364, 0.9374], [0.7924, 0.9101], [0.8332, 0.8728], [0.8613, 0.8263], [0.8884, 0.7469], [0.94, 0.5187], [0.9496, 0.4439], [0.9441, 0.3678], [0.9138, 0.3127], [0.8682, 0.2824], [0.7893, 0.2587], [0.6801, 0.2439], [0.5542, 0.2405], [0.441, 0.2477], [0.3589, 0.2616], [0.272, 0.2902]]];
    const footPath = (W, H, mirror) => FOOT_PARTS.map((pts) => 'M' + pts.map(([x, y]) => `${(mirror ? 1 - x : x) * W} ${y * H}`).join(' L') + ' Z').join(' ');
    // Fonds illustrés (sélection d'Adrien). mm = largeur de l'image sur l'étiquette ; text = couleur auto des lettres (toujours foncée + liseré blanc : lisible sur tous les fonds)
    const PATS = [{"id": "cactus", "label": "Cactus", "mm": 55, "ratio": 1.0, "avg": "#424D43", "text": "#2D2D31"}, {"id": "feuilles", "label": "Feuilles", "mm": 50, "ratio": 1.0, "avg": "#675D42", "text": "#2D2D31"}, {"id": "chats", "label": "Chats", "mm": 45, "ratio": 1.0, "avg": "#D4B3A1", "text": "#2D2D31"}, {"id": "abeilles", "label": "Abeilles", "mm": 50, "ratio": 1.0, "avg": "#B8C4BD", "text": "#2D2D31"}, {"id": "marguerites", "label": "Marguerites", "mm": 50, "ratio": 1.0, "avg": "#546063", "text": "#2D2D31"}, {"id": "mer", "label": "Fonds marins", "mm": 45, "ratio": 1.0, "avg": "#D4C7B7", "text": "#2D2D31"}, {"id": "noel", "label": "Noël", "mm": 60, "ratio": 1.4287, "avg": "#3A3E35", "text": "#2D2D31"}, {"id": "herbier", "label": "Herbier", "mm": 55, "ratio": 1.0, "avg": "#CBCBCB", "text": "#2D2D31"}, {"id": "automne", "label": "Automne", "mm": 60, "ratio": 0.6665, "avg": "#F1E4A0", "text": "#2D2D31"}, {"id": "tortues", "label": "Tortues", "mm": 55, "ratio": 1.0, "avg": "#417285", "text": "#2D2D31"}, {"id": "monstres", "label": "Monstres", "mm": 50, "ratio": 1.5, "avg": "#6F8F36", "text": "#2D2D31"}, {"id": "baleines", "label": "Baleines", "mm": 60, "ratio": 1.0, "avg": "#D4D9D9", "text": "#2D2D31"}, {"id": "poissons", "label": "Poissons-globes", "mm": 55, "ratio": 1.0, "avg": "#E3DECB", "text": "#2D2D31"}, {"id": "nuages", "label": "Nuages", "mm": 70, "ratio": 0.5625, "avg": "#E8EFF5", "text": "#2D2D31"}, {"id": "singes", "label": "Singes", "mm": 60, "ratio": 1.0, "avg": "#D5C780", "text": "#2D2D31"}];
    const PAT = Object.fromEntries(PATS.map((p) => [p.id, p]));
    // Style du fond à l'échelle k px/mm pour une étiquette W × H mm : image centrée, assez grande pour couvrir
    const patCss = (kind, bg, fg, k, Wmm, Hmm) => {
      const p = PAT[kind];
      if (!p) return { cls: '', css: `background: ${bg};` };
      const T = Math.max(p.mm, (Wmm || 0) * 1.05, ((Hmm || 0) / p.ratio) * 1.05);
      return { cls: `pt-${p.id}`, css: `background-color: ${p.avg}; background-size: ${T * k}px ${T * p.ratio * k}px; background-position: center; background-repeat: no-repeat;` };
    };
    const ring = (on, px) => on ? `text-shadow: ${[[1,0],[-1,0],[0,1],[0,-1],[.7,.7],[-.7,.7],[.7,-.7],[-.7,-.7]].map(([x, y]) => `${x * px}px ${y * px}px 0 #fff`).join(', ')};` : '';
    // Couleur des lettres : « auto » = lisible selon le fond, sinon couleur imposée par le client
    const TEXT_COLORS = [['#2D2D31', 'Noir'], ['#FFFFFF', 'Blanc'], ['#C62F35', 'Rouge'], ['#2F6FB5', 'Bleu'], ['#6E52C2', 'Violet'], ['#3E9C8C', 'Vert d\u2019eau'], ['#EE5F90', 'Rose'], ['#F2994A', 'Orange'], ['#F7C548', 'Jaune']];
    const PICTOS = [
      ['none', 'Aucune'],
      ['i-licorne', 'Licorne'], ['i-pompier', 'Camion de pompier'], ['i-fleur', 'Fleur'], ['i-dino', 'Dinosaure'],
      ['i-fusee', 'Fusée'], ['i-chat', 'Chat'], ['i-arc', 'Arc-en-ciel'], ['i-papillon', 'Papillon']
    ];
    const unitOf = (id, n) => id === 'chaussure' ? `étiquettes (${Math.floor(n / 2)} paires)` : 'étiquettes';
    const dimOf = (p) => p.round ? `Ø ${p.w} mm` : (p.foot ? `${p.h} × ${p.w} mm` : `${p.w} × ${p.h} mm`);

    // Mesure réelle de la largeur d'un texte (canvas), pour que le prénom tienne toujours sur l'étiquette
    if (!this._cv && typeof document !== 'undefined') {
      this._cv = document.createElement('canvas').getContext('2d');
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => this.setState({ fontsReady: true }));
      this.loadTarifs();
    }
    const textW = (text, f, px, bold, spacing) => {
      const ctx = this._cv;
      let w;
      if (ctx) {
        ctx.font = `${f && f.italic ? 'italic ' : ''}${bold ? 700 : (f ? f.weight : 700)} ${px}px ${f ? f.css : "'Archivo', sans-serif"}`;
        w = ctx.measureText(text).width;
        if (f && f.italic) w *= 0.8;          // police sportive en largeur 75 %
      } else w = text.length * px * 0.62;
      return w + (spacing ? text.length * spacing * px : 0);
    };
    // Taille maxi pour que le texte tienne dans « avail » px (contour blanc compris)
    const fitPx = (text, f, want, avail, bold, spacing, ringK) => {
      if (!text) return want;
      const w1 = textW(text, f, 100, bold, spacing) / 100 + 2 * (ringK || 0);
      return Math.max(3, Math.min(want, (avail * 0.96) / w1));
    };

    // Styles d'une étiquette (config c) à l'échelle k px/mm ; rot = tournée d'un quart de tour sur la planche
    const labelAt = (c, k, shadow, rot) => {
      const p = PRODUCTS[c.product], f = FONTS[c.font], col = COLORS[c.color];
      const colLayout = !!(p.round || p.foot), W = p.w * k, H = p.h * k, short = Math.min(W, H);
      const pp = PAT[c.pattern];
      const bg = pp ? pp.avg : col.bg, fg = (c.textColor && c.textColor !== 'auto') ? c.textColor : (pp ? pp.text : col.fg);
      const pc = patCss(c.pattern, bg, fg, k, p.w, p.h);
      const name = (c.name || '').trim() || 'Prénom';
      const l2 = (c.line2 || '').trim();
      const has2 = !!l2 && !p.inline;
      const txt = p.inline && l2 ? `${name} ${l2.toUpperCase()}` : name;
      const noIco0 = !!(p.foot || PAT[c.pattern] || (c.picto === 'none' && !c.customUrl));
      const outline0 = !!pp && fg !== '#FFFFFF';
      const ico = colLayout ? short * 0.42 : H * 0.8;
      const noIco1 = !!(p.foot || pp || (c.picto === 'none' && !c.customUrl));
      // place disponible pour le texte
      const avail = p.foot ? H * 0.5 : (p.round ? W * 0.74 : W - 2 * H * 0.18 - (noIco1 ? 0 : ico + H * 0.14));
      const want = colLayout ? short * (noIco1 ? 0.26 : 0.22) : (p.inline ? H * 0.62 : H * (has2 ? 0.4 : 0.55));
      const ns = fitPx(txt, f, want, avail, false, 0, outline0 ? 0.14 : 0);
      const l2want = Math.max(4, colLayout ? short * 0.13 : H * 0.24);
      const l2px = has2 ? fitPx(l2.toUpperCase(), null, l2want, avail, true, 0.04, outline0 ? 0.22 : 0) : l2want;
      const radius = p.round ? '999px' : (p.foot ? '0' : `${p.r * k}px`);
      const vars = c.auto ? `--i1: ${fg}; --i2: color-mix(in srgb, ${fg} 65%, ${bg}); --i3: color-mix(in srgb, ${fg} 40%, ${bg}); --e: ${bg}; --k: ${fg};` : '';
      const outline = !!pp && fg !== '#FFFFFF';
      // Pas d'illustration (fond illustré, « Aucune », pied) : le texte se recentre
      const noIcon = !!(p.foot || pp || (c.picto === 'none' && !c.customUrl));
      return {
        pcls: pc.cls, text: txt, has2,
        box: `width: ${W}px; height: ${H}px; flex: none; border-radius: ${radius}; ${pc.css} color: ${fg}; ${colLayout ? `flex-direction: column; justify-content: center; gap: ${short * 0.03}px; padding: 0 ${short * 0.08}px;` : `padding: 0 ${H * 0.18}px; gap: ${H * 0.14}px;`} ${p.foot ? `clip-path: path('${footPath(W, H, false)}'); padding-top: ${H * 0.28}px;` : ''} ${noIcon && !colLayout ? 'justify-content: center;' : ''} ${shadow ? 'box-shadow: 0 6px 18px rgba(0,0,0,.35);' : ''} ${rot ? 'transform: rotate(-90deg);' : ''}`,
        icon: (noIcon || c.customUrl) ? 'display: none' : `width: ${ico}px; height: ${ico}px; flex: none; ${vars}`,
        img: (p.foot || pp || !c.customUrl) ? 'display: none' : `width: ${ico}px; height: ${ico}px; flex: none; object-fit: contain`,
        col: `display: flex; flex-direction: column; min-width: 0; ${(colLayout || noIcon) ? 'align-items: center; text-align: center;' : ''} ${p.foot ? 'transform: rotate(-90deg); white-space: nowrap;' : ''}`,
        nameS: `font-family: ${f.css}; font-weight: ${f.weight}; ${f.italic ? 'font-style: italic; font-stretch: 75%;' : ''} font-size: ${ns}px; line-height: 1.05; ${ring(outline, Math.max(2, ns * 0.14))}`,
        line2: (() => { const l2s = l2px; const rw = Math.max(2, l2s * 0.22);
          // le contour dépasse de la boîte : marge intérieure pour qu'il ne soit pas coupé
          return `font-weight: 700; font-size: ${l2s}px; letter-spacing: .04em; overflow: hidden; text-overflow: ellipsis; ${outline ? `padding: ${rw}px; margin: -${rw}px;` : ''} ${ring(outline, rw)}`; })()
      };
    };

    const eff = (p) => Object.assign({}, s.common, p.own || {}, { id: p.id, product: p.product, qty: p.qty, own: p.own });
    const selP = s.planches.find((p) => p.id === s.sel) || s.planches[0];
    const cur = eff(selP);
    const prod = PRODUCTS[cur.product];
    const lay = layout(prod);
    const BIG_K = { thermo: 5.5, mini: 7, chaussure: 4.5, ronde: 5, stylo: 6 }[cur.product];
    const K = 2.5;
    const big = labelAt(cur, BIG_K, true, false), small = labelAt(cur, K, false, lay.rot);
    const total = s.planches.reduce((a, p) => a + p.qty, 0);
    // Règle : le carnet doit contenir au moins la composition d'un pack.
    // Prix = pack contenu le plus avantageux + planches en plus (prix en centimes, publiés par l'atelier).
    const PACKS = TARIFS.packs.map((p) => ({ name: p.label, price: p.cents, c: p.content }));
    const LBL = { thermo: 'thermocollante', mini: 'mini', chaussure: 'chaussures', ronde: 'rondes', stylo: 'stylo' };
    const unitEur = (k) => (k === 'thermo' ? TARIFS.unit.thermo : TARIFS.unit.adhesive);
    const have = {};
    s.planches.forEach((p) => { have[p.product] = (have[p.product] || 0) + p.qty; });
    let best = null;
    PACKS.forEach((pk) => {
      const missing = Object.keys(pk.c).reduce((a, k) => a + Math.max(0, pk.c[k] - (have[k] || 0)), 0);
      const extra = Object.keys(have).reduce((a, k) => a + Math.max(0, have[k] - (pk.c[k] || 0)) * unitEur(k), 0);
      const extraN = Object.keys(have).reduce((a, k) => a + Math.max(0, have[k] - (pk.c[k] || 0)), 0);
      const o = { pk, missing, price: pk.price + extra, extraN };
      if (!best || (o.missing === 0 && (best.missing > 0 || o.price < best.price)) || (best.missing > 0 && o.missing < best.missing)) best = o;
    });
    const staffOn = !!s.staffCode;
    if (staffOn) {   // composition libre : toutes les planches au prix unitaire (ou prix fixé à la main)
      const unitSum = Object.keys(have).reduce((a, k) => a + have[k] * unitEur(k), 0);
      const manual = Math.round(parseFloat(String(s.staffPrice || '').replace(',', '.')) * 100);
      best = { pk: { name: 'Commande atelier', price: 0, c: {} }, missing: 0, extraN: 0,
        price: s.staffPrice !== '' && manual >= 0 ? manual : unitSum, manual: s.staffPrice !== '' && manual >= 0 };
    }
    const packOk = best.missing === 0;
    const missingTxt = packOk ? '' : Object.keys(best.pk.c).filter((k) => (have[k] || 0) < best.pk.c[k])
      .map((k) => { const n = best.pk.c[k] - (have[k] || 0); return `${n} planche${n > 1 ? 's' : ''} ${LBL[k]}`; }).join(', ');
    const on = (b, base) => base + (b ? ' on' : '');

    // Étiquette chaussures : paire de pieds (gauche + droit en miroir), texte posé dans la semelle
    const isFoot = !!prod.foot;
    const makeFoot = (fk, shadow) => {
    const fW = 28 * fk, fH = 44 * fk;
    const fpat = PAT[cur.pattern];
    const fc0 = fpat ? { bg: fpat.avg, fg: fpat.text } : COLORS[cur.color];
    const fc = { bg: fc0.bg, fg: (cur.textColor && cur.textColor !== 'auto') ? cur.textColor : fc0.fg }, ff = FONTS[cur.font];
    const fpc = patCss(cur.pattern, fc.bg, fc.fg, fk, 28, 44);
    const fName = big.text;
    const footText = (left) => `position: absolute; left: ${left}%; top: 64%; transform: translate(-50%, -50%) rotate(-90deg); display: flex; flex-direction: column; align-items: center; white-space: nowrap; line-height: 1.05; color: ${fc.fg}`;
    const fOutline = !!fpat && fc.fg !== '#FFFFFF';
    const fNs = fitPx(fName, ff, fW * 0.3, fH * 0.5, false, 0, fOutline ? 0.14 : 0);
    const fL2 = fitPx((cur.line2 || '').toUpperCase(), null, fW * 0.17, fH * 0.5, true, 0.04, fOutline ? 0.22 : 0);
    const foot = {
      wrap: `position: relative; width: ${fW}px; height: ${fH}px; flex: none; ${shadow ? 'filter: drop-shadow(0 6px 14px rgba(0,0,0,.35))' : ''}`,
      pcls: fpc.cls,
      svgL: `position: absolute; inset: 0; ${fpc.css} clip-path: path('${footPath(fW, fH, false)}')`,
      svgR: `position: absolute; inset: 0; ${fpc.css} clip-path: path('${footPath(fW, fH, true)}')`,
      textL: footText(48), textR: footText(52),
      nameS: `font-family: ${ff.css}; font-weight: ${ff.weight}; ${ff.italic ? 'font-style: italic; font-stretch: 75%;' : ''} font-size: ${fNs}px; ${ring(fOutline, fNs * 0.14)}`,
      l2: `font-weight: 700; font-size: ${fL2}px; letter-spacing: .04em; ${ring(fOutline, Math.max(1.2, fL2 * 0.22))}`
    };
    return foot;
    };
    const foot = makeFoot(4.6, true);
    const footMini = makeFoot(1.45, false);
    // Aperçu permanent en bas d'écran (mobile) : même étiquette, en petit
    const MK = Math.min(150 / prod.w, 56 / prod.h);
    const mini = labelAt(cur, MK, false, false);


    // Fenêtre d'accueil : aperçu en direct de ce que la personne tape
    const d = s.draft;
    const wLab = labelAt(Object.assign({}, s.common, { product: 'thermo', name: d.name || 'Prénom', line2: d.line2 }), 6, true, false);
    const wOk = !!(d.name || '').trim();
    const closeWelcome = () => this.setState({ welcome: false });
    const submitWelcome = () => {
      if (!wOk) return;
      this.setState({ welcome: false, common: Object.assign({}, s.common, { name: d.name.trim(), line2: (d.line2 || '').trim() }) });
    };

    return {
      name: cur.name, line2: cur.line2, picto: cur.picto,
      displayName: big.text, hasLine2: big.has2, line2Upper: (cur.line2 || '').toUpperCase(),
      big, small, foot, footMini, mini, isFoot, notFoot: !isFoot,
      welcome: !!s.welcome, wLab, wName: d.name, wLine2: d.line2, wHas2: !!(d.line2 || '').trim(), wText: d.name || 'Prénom', wLine2Up: (d.line2 || '').toUpperCase(),
      setWName: (e) => this.setState({ draft: Object.assign({}, s.draft, { name: e.target.value }) }),
      setWLine2: (e) => this.setState({ draft: Object.assign({}, s.draft, { line2: e.target.value }) }),
      submitWelcome, closeWelcome,
      wBtnStyle: wOk ? '' : 'opacity: .45; cursor: not-allowed',
      scopeAll: s.scope === 'all' ? 'on' : '', scopeOne: s.scope === 'one' ? 'on' : '',
      scopeAllChecked: s.scope === 'all' ? 'true' : 'false', scopeOneChecked: s.scope === 'one' ? 'true' : 'false',
      setScopeAll: () => this.setState({ scope: 'all' }), setScopeOne: () => this.setState({ scope: 'one' }),
      scopeHint: s.scope === 'all'
        ? (selP.own ? 'Cette planche a un design perso : vos changements s\u2019appliquent aux autres planches.' : 'Prénom, couleur, illustration, fond… s\u2019appliquent à toutes les planches du carnet.')
        : `Vos changements ne touchent que la planche ${PRODUCTS[selP.product].label.toLowerCase()}.`,
      hasOwn: !!selP.own,
      resetOwn: () => this.setState({ planches: s.planches.map((p) => p.id === selP.id ? Object.assign({}, p, { own: null }) : p) }),
      count: lay.n,
      countLine: isFoot ? `étiquettes, soit ${Math.floor(lay.n / 2)} paires` : 'étiquettes identiques',
      cropTitle: `Planche ${prod.label.toLowerCase()}`,
      cropSub: `${big.text} · ${dimOf(prod)} · 22 × 10 cm`,
      perSheet: `${lay.n} ${unitOf(cur.product, lay.n)}`,
      cellStyle: `width: ${lay.cw * K}px; height: ${lay.ch * K}px; display: flex; align-items: center; justify-content: center`,
      cells: Array.from({ length: lay.n }, (_, i) => ({ i })),
      sheetStyle: `width: ${SW * K}px; height: ${SH * K}px; padding: ${M * K}px; grid-template-columns: repeat(${lay.cols}, ${lay.cw * K}px); grid-auto-rows: ${lay.ch * K}px; gap: ${G * K}px`,
      hasCustom: !!cur.customUrl, noCustom: !cur.customUrl, customUrl: cur.customUrl || '',
      importTitle: s.uploading ? 'Envoi de l\u2019image…' : (cur.customUrl ? 'Image importée ✓ · en choisir une autre' : 'Importer mon image'),
      importBg: cur.customUrl ? '#EEF5DD' : '#FFFFFF',
      onImport: (e) => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        if (f.size > 15 * 1024 * 1024) { this.setState({ uploadError: 'Image trop lourde (15 Mo au maximum).' }); return; }
        this.setState({ uploading: true, uploadError: '' });
        const fd = new FormData();
        fd.append('image', f);
        fetch('/api/images', { method: 'POST', body: fd })
          .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
          .then(({ ok, d }) => {
            if (!ok) throw new Error(d.message || 'Envoi impossible');
            this.setState({ uploading: false });
            this.update({ customUrl: d.url, imageId: d.id });
          })
          .catch((err) => this.setState({ uploading: false, uploadError: err.message || 'Envoi impossible, réessayez.' }));
      },
      clearCustom: () => this.update({ customUrl: '', imageId: '' }),
      uploading: !!s.uploading, uploadError: s.uploadError, hasUploadError: !!s.uploadError,
      segAuto: cur.auto ? 'on' : '', segOrig: cur.auto ? '' : 'on',
      autoChecked: cur.auto ? 'true' : 'false', origChecked: cur.auto ? 'false' : 'true',
      setAuto: () => this.update({ auto: true }), setOrig: () => this.update({ auto: false }),
      setName: (e) => this.update({ name: e.target.value }),
      setLine2: (e) => this.update({ line2: e.target.value }),
      cartLine: `Mon carnet · ${total} planche${total > 1 ? 's' : ''}`,
      minOk: packOk, minKo: !packOk,
      priceTxt: packOk ? eur(best.price) : '\u2014',
      priceNote: staffOn ? (best.manual ? 'Commande atelier · prix fixé' : 'Commande atelier · planches au prix unitaire') : packOk ? `${best.pk.name} (${eur(best.pk.price)})${best.extraN ? ` + ${best.extraN} planche${best.extraN > 1 ? 's' : ''} en plus` : ''}` : '',
      priceNoteOn: packOk,
      minMsg: packOk ? '' : `Votre carnet doit contenir au moins la composition d\u2019un pack. Pour le ${best.pk.name.toLowerCase()}, ajoutez : ${missingTxt}.`,
      validateStyle: packOk ? '' : 'opacity: .45; cursor: not-allowed',
      openCheckout: () => { if (packOk) this.setState({ checkout: true, orderError: '', fieldErrors: {} }); },
      closeCheckout: () => this.setState({ checkout: false }),
      checkout: !!s.checkout && !s.done, done: !!s.done, doneRef: s.done ? s.done.ref : '', doneTrack: s.done ? s.done.track_url : '',
      doneTotal: s.done ? (s.done.ttc_cents / 100).toFixed(2).replace('.', ',') + ' €' : '', doneEmail: s.done ? s.done.email : '',
      stepCheck: s.checkout || s.done ? 'on' : '',
      cName: s.customer.name, cEmail: s.customer.email, cPhone: s.customer.phone,
      setCName: (e) => this.setState({ customer: Object.assign({}, s.customer, { name: e.target.value }) }),
      setCEmail: (e) => this.setState({ customer: Object.assign({}, s.customer, { email: e.target.value }) }),
      setCPhone: (e) => this.setState({ customer: Object.assign({}, s.customer, { phone: e.target.value }) }),
      consentChecked: s.consent ? 'true' : 'false',
      toggleConsent: (e) => this.setState({ consent: e.target.checked }),
      errName: (s.fieldErrors || {}).name || '', errEmail: (s.fieldErrors || {}).email || '', errPhone: (s.fieldErrors || {}).phone || '', errConsent: (s.fieldErrors || {}).consent || '',
      hasErrName: !!(s.fieldErrors || {}).name, hasErrEmail: !!(s.fieldErrors || {}).email, hasErrPhone: !!(s.fieldErrors || {}).phone, hasErrConsent: !!(s.fieldErrors || {}).consent,
      orderError: s.orderError, hasOrderError: !!s.orderError, sending: !!s.sending,
      sendLabel: s.sending ? 'Envoi en cours…' : `Commander · ${packOk ? eur(best.price) : ''}`,
      recap: s.planches.map((p) => { const e = eff(p); return { label: `${p.qty} × ${PRODUCTS[p.product].label}`, design: `${(e.name || '').trim() || 'Prénom'}${(e.line2 || '').trim() ? ' ' + e.line2.trim() : ''}` }; }),
      recapPack: staffOn ? (best.manual ? 'Commande atelier, prix fixé à la main' : 'Commande atelier : planches au prix unitaire') : packOk ? `${best.pk.name} ${eur(best.pk.price)}${best.extraN ? ` + ${best.extraN} planche${best.extraN > 1 ? 's' : ''} en plus` : ''}` : '',
      newOrder: () => { try { localStorage.removeItem(STORE_KEY); } catch (e) { /* rien */ } location.href = '/composer'; },
      staffOn: staffOn, staffAsk: !!s.staffAsk, staffErr: s.staffErr, hasStaffErr: !!s.staffErr,
      staffInput: s.staffInput, staffPrice: s.staffPrice, staffUnitTxt: eur(Object.keys(have).reduce((a, k) => a + have[k] * unitEur(k), 0)),
      setStaffInput: (e) => this.setState({ staffInput: e.target.value }),
      setStaffPrice: (e) => this.setState({ staffPrice: e.target.value }),
      closeStaff: () => this.setState({ staffAsk: false, staffInput: '', staffErr: '' }),
      staffKey: (e) => { if (e.key === 'Enter') this.staffLogin(); },
      staffLogin: () => this.staffLogin(),
      staffQuit: () => { try { sessionStorage.removeItem('cc-atelier'); } catch (e) { /* rien */ } this.setState({ staffCode: '', staffPrice: '' }); },
      titleTap: () => {
        const now = Date.now();
        this._taps = (this._taps || []).filter((t) => now - t < 1500).concat([now]);
        if (this._taps.length >= 5) { this._taps = []; this.setState({ staffAsk: true, staffInput: '', staffErr: '' }); }
      },
      sendOrder: () => {
        if (s.sending || !packOk) return;
        const designOf = (p) => { const e = eff(p); return { name: (e.name || '').trim(), line2: (e.line2 || '').trim(), font: e.font, color: e.color,
          picto: e.picto, auto: !!e.auto, pattern: e.pattern || 'uni', textColor: e.textColor || 'auto', imageId: e.customUrl ? (e.imageId || '') : '' }; };
        this.setState({ sending: true, orderError: '', fieldErrors: {} });
        fetch('/api/orders', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
          customer: s.customer, consent: !!s.consent, expected_ttc_cents: best.price,
          staff_code: staffOn ? s.staffCode : undefined, staff_ttc_cents: staffOn && best.manual ? best.price : undefined,
          planches: s.planches.map((p) => ({ format: p.product, qty: p.qty, design: designOf(p) }))
        }) })
          .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
          .then(({ ok, d }) => {
            if (!ok) {
              if (d.error === 'bad_staff_code') { try { sessionStorage.removeItem('cc-atelier'); } catch (e) { /* rien */ } this.setState({ staffCode: '' }); }
              if (d.error === 'price_changed') this.loadTarifs();   // tarifs changés entre-temps : on affiche les nouveaux
              this.setState({ sending: false, orderError: d.message || 'Commande impossible, réessayez.', fieldErrors: d.fields || {} }); return;
            }
            try { localStorage.removeItem(STORE_KEY); } catch (e) { /* rien */ }
            this.setState({ staffPrice: '' });
            this.setState({ sending: false, done: Object.assign({ email: s.customer.email }, d) });
            window.scrollTo(0, 0);
          })
          .catch(() => this.setState({ sending: false, orderError: 'Connexion perdue : vérifiez votre réseau et réessayez.' }));
      },
      addPlanche: () => {
        const id = s.nextId;
        this.setState({ planches: s.planches.concat([{ id, product: 'stylo', qty: 1, own: null }]), sel: id, nextId: id + 1 });
      },
      thumbs: s.planches.map((p) => {
        const l = labelAt(eff(p), p.product === 'chaussure' ? 1.4 : 1.6, false, false);
        const e = eff(p);
        const pp = PRODUCTS[p.product];
        return {
          type: pp.label, name: l.text,
          box: l.box, pcls: 'lab ' + l.pcls, icon: l.icon, nameStyle: l.nameS,
          hasImg: !!e.customUrl, noImg: !e.customUrl, img: e.customUrl || '', imgStyle: l.img, ill: e.picto, perso: !!p.own,
          sub: `${p.qty > 1 ? p.qty + ' planches' : '1 planche'} · ${layout(pp).n} étiquettes`,
          qty: p.qty,
          outline: p.id === s.sel ? 'outline: 2px solid #2D2D31; outline-offset: -1px' : '',
          select: () => this.setState({ sel: p.id }),
          remove: () => {
            if (s.planches.length < 2) return;
            const rest = s.planches.filter((x) => x.id !== p.id);
            this.setState({ planches: rest, sel: p.id === s.sel ? rest[0].id : s.sel });
          },
          minus: () => this.setState({ planches: s.planches.map((x) => x.id === p.id ? Object.assign({}, x, { qty: Math.max(1, x.qty - 1) }) : x) }),
          plus: () => this.setState({ planches: s.planches.map((x) => x.id === p.id ? Object.assign({}, x, { qty: x.qty + 1 }) : x) })
        };
      }),
      products: Object.keys(PRODUCTS).map((id) => ({ label: PRODUCTS[id].label, sub: `${layout(PRODUCTS[id]).n} / planche · ${dimOf(PRODUCTS[id])}`, cls: on(id === cur.product, 'fmtb'), pressed: id === cur.product ? 'true' : 'false', pick: () => this.setProduct(id) })),
      fonts: Object.keys(FONTS).map((id) => { const f = FONTS[id]; return { sample: f.sample, style: `font-family: ${f.css}; font-weight: ${f.weight}; ${f.italic ? 'font-style: italic; font-stretch: 75%;' : ''} font-size: 18px`, cls: on(id === cur.font, 'fmtb'), pressed: id === cur.font ? 'true' : 'false', pick: () => this.update({ font: id }) }; }),
      colors: Object.keys(COLORS).map((id) => ({ label: COLORS[id].label, pressed: id === cur.color ? 'true' : 'false', style: `background: ${COLORS[id].bg}; box-shadow: 0 0 0 ${id === cur.color ? '2px #2D2D31' : '1px #E2E2DD'}`, pick: () => this.update({ color: id }) })),
      patterns: PATS.map(({ id, label }) => { const on_ = id === (cur.pattern || 'uni'); const pc = patCss(id, COLORS[cur.color].bg, COLORS[cur.color].fg, 1.6, 40, 20); return { label, cls: on(on_, 'fmtb pat'), pressed: on_ ? 'true' : 'false', swcls: pc.cls, swatch: `display: block; width: 100%; height: 34px; border-radius: 6px; ${pc.css}`, pick: () => { this.lastPat = id; this.update({ pattern: id }); } }; }),
      colorHint: PAT[cur.pattern] ? 'Avec un fond illustré, ces couleurs ne servent pas : choisissez la couleur des lettres juste en dessous.' : '',
      hasColorHint: !!PAT[cur.pattern],
      isUni: !PAT[cur.pattern], isPat: !!PAT[cur.pattern],
      modeUni: PAT[cur.pattern] ? '' : 'on', modePat: PAT[cur.pattern] ? 'on' : '',
      modeUniChecked: PAT[cur.pattern] ? 'false' : 'true', modePatChecked: PAT[cur.pattern] ? 'true' : 'false',
      setModeUni: () => this.update({ pattern: 'uni' }),
      setModePat: () => this.update({ pattern: PAT[this.lastPat] ? this.lastPat : 'abeilles' }),
      textAutoCls: on((cur.textColor || 'auto') === 'auto', 'fmtb'), textAutoPressed: (cur.textColor || 'auto') === 'auto' ? 'true' : 'false',
      setTextAuto: () => this.update({ textColor: 'auto' }),
      // Sélecteur libre : n'importe quelle couleur en plus de la sélection
      customTextValue: (cur.textColor && cur.textColor !== 'auto' && !TEXT_COLORS.some(([h]) => h === cur.textColor)) ? cur.textColor : '#8B6FD6',
      customTextOn: !!(cur.textColor && cur.textColor !== 'auto' && !TEXT_COLORS.some(([h]) => h === cur.textColor)),
      customTextStyle: `width: 44px; height: 44px; border-radius: 50%; border: 3px solid #fff; padding: 0; cursor: pointer; background: conic-gradient(#E5484D, #F7C548, #3E9C8C, #2F6FB5, #8B6FD6, #EE5F90, #E5484D); box-shadow: 0 0 0 ${(cur.textColor && cur.textColor !== 'auto' && !TEXT_COLORS.some(([h]) => h === cur.textColor)) ? '2px #2D2D31' : '1px #CFCFC8'}; position: relative; overflow: hidden; display: inline-block`,
      setTextCustom: (e) => this.update({ textColor: e.target.value.toUpperCase() }),
      textColors: TEXT_COLORS.map(([hex, label]) => ({ label, pressed: cur.textColor === hex ? 'true' : 'false', style: `background: ${hex}; box-shadow: 0 0 0 ${cur.textColor === hex ? '2px #2D2D31' : '1px #CFCFC8'}`, pick: () => this.update({ textColor: hex }) })),
      pictos: PICTOS.map(([id, label]) => ({ id, label, txt: id === 'none' ? 'Aucune' : '', hasSvg: id !== 'none', cls: on(id === cur.picto, 'fmtb'), pressed: id === cur.picto ? 'true' : 'false', pick: () => this.update({ picto: id, customUrl: '', imageId: '' }) }))
    };
  }
}

window.addEventListener('DOMContentLoaded', function () {
  mountComponent(Component, document.getElementById('app'), document.getElementById('tpl'));
});
