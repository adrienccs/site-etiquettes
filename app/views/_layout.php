<?php
/** @var string $title  @var string $content  @var array $cfg */
use CopyColor\Etiquettes\Util;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= Util::h($title) ?> · Copy Color étiquettes</title>
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/assets/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Archivo:ital,wdth,wght@0,62..125,400..800;1,62..125,400..800&display=swap" rel="stylesheet">
<style>
:root{--tf:'Archivo';--tfs:italic;--tfw:800;--tfx:75%;--tfl:0;--tft:none;--tfz:1;--ink:#2D2D31;--muted:#66666B;--line:#E2E2DD;--bg:#F4F4F1;--warn:#FEF3C7;--warn-ink:#7A4A00;
  --dark:#2D2D31;--on-dark:#FFFFFF;--acc:#B1D36B;--on-acc:#2D2D31;--acc-soft:#EEF5DD;--hl:#B03C92;--s1:#EE5F90;--s2:#F05A2E;--s3:#A8292C;--s4:#B03C92}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:'Archivo',system-ui,sans-serif;font-size:16px;line-height:1.45}
header{background:#fff;padding:10px 20px}
.brand{--lh:54px;display:inline-block;white-space:nowrap;line-height:1;text-decoration:none;color:var(--ink);padding-bottom:calc(var(--lh) * .152)}
.brand img{height:var(--lh);width:auto;display:inline-block;vertical-align:baseline;margin-bottom:calc(var(--lh) * -.152)}
.brand span{display:inline-block;margin-left:calc(var(--lh) * .12);font-size:calc(calc(var(--lh) * .56) * var(--tfz));word-spacing:.14em;font-family:var(--tf);font-style:var(--tfs);font-weight:var(--tfw);font-stretch:var(--tfx);letter-spacing:var(--tfl);text-transform:var(--tft)}
@media (max-width:699px){header{padding:10px 14px}.brand{--lh:42px}}
header{position:sticky;top:0;z-index:40;padding:12px max(24px,calc((100% - 1192px) / 2));border-bottom:4px solid transparent;border-image:linear-gradient(90deg,var(--s1) 0 25%,var(--s2) 25% 50%,var(--s3) 50% 75%,var(--s4) 75% 100%) 1}
header + .stripes{display:none}
@media (max-width:640px){header{padding:10px 16px}.brand{--lh:44px}}
.stripes{display:flex;height:4px}.stripes i{flex:1}
.stripes i:nth-child(1){background:var(--s1)}.stripes i:nth-child(2){background:var(--s2)}.stripes i:nth-child(3){background:var(--s3)}.stripes i:nth-child(4){background:var(--s4)}
.brand.partner{display:flex;align-items:center;gap:calc(var(--lh) * .3);padding-bottom:0}
.brand.partner img{margin:0;max-width:min(230px,52vw);object-fit:contain;vertical-align:middle}
.brand.partner span{margin-left:0}
.brand.partner b{font-size:calc(calc(var(--lh) * .56) * var(--tfz));color:var(--hl);font-family:var(--tf);font-style:var(--tfs);font-weight:var(--tfw);font-stretch:var(--tfx);letter-spacing:var(--tfl);text-transform:var(--tft)}
.ccband{display:flex;align-items:center;justify-content:center;gap:10px;padding:7px 16px;background:var(--acc);color:var(--on-acc);font-size:13.5px;font-weight:600;flex-wrap:wrap}
.ccband img{height:28px;background:#fff;border-radius:6px;padding:3px 7px}
.proband{display:flex;align-items:center;justify-content:center;gap:8px;padding:8px 16px;background:var(--dark);color:var(--on-dark);font-size:13.5px;font-weight:700}
.proband .chip,.chip{background:var(--acc);color:var(--on-acc);border-radius:6px;padding:2px 8px;font-size:12px;font-weight:800;letter-spacing:.03em}
a{color:var(--hl)}
main{max-width:640px;margin:0 auto;padding:24px 16px 48px}
h1{font-size:calc(34px * var(--tfz));flex-wrap:wrap;line-height:1;margin:0 0 6px;display:flex;align-items:center;gap:12px;font-family:var(--tf);font-style:var(--tfs);font-weight:var(--tfw);font-stretch:var(--tfx);letter-spacing:var(--tfl);text-transform:var(--tft)}
h1::after{content:"";display:inline-block;width:5px;height:30px;background:var(--acc);transform:skewX(-14deg)}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin:16px 0}
.muted{color:var(--muted)}
.row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:6px 0}
.row>strong,.total,.nw{white-space:nowrap}
.total{font-size:24px;font-weight:800}
.notice{background:var(--acc-soft);border-radius:12px;padding:12px 14px;margin:16px 0}
.steps{display:flex;gap:6px;margin:16px 0}
.steps div{flex:1;display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--muted)}
.steps div::before{content:"";height:4px;border-radius:2px;background:#DADAD4}
.steps div.done{color:var(--ink);font-weight:700}.steps div.done::before{background:var(--dark)}
button{font:inherit;font-weight:800;height:52px;padding:0 20px;border-radius:12px;border:1px solid #C9C9C3;background:#fff;color:var(--ink);cursor:pointer}
button.danger{border-color:#A8292C;color:#A8292C}
label.check{display:flex;gap:10px;align-items:flex-start;font-size:14px;margin:12px 0}
label.check input{width:20px;height:20px;margin:0;accent-color:var(--ink)}
footer{text-align:center;font-size:13px;color:var(--muted);padding:0 16px 32px}
.offert{background:var(--acc);color:var(--on-acc);border-radius:6px;padding:1px 8px;font-size:.9em;white-space:nowrap}
</style>
</head>
<body>
<header><a class="brand" href="/" aria-label="Copy Color Service, étiquettes"><img src="/assets/logo.png" alt="Copy Color Service"><span>étiquettes</span></a></header>
<div class="stripes"><i></i><i></i><i></i><i></i></div>
<main><?= $content ?></main>
<footer><?= Util::h($cfg['shop']['name'] . ' · ' . $cfg['shop']['address'] . ' · ' . $cfg['shop']['phone']) ?></footer>
</body>
</html>
