<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Precio de Combustibles
 * ============================================================
 * Archivo  : combustibles.php
 * Versión  : 1.0.0
 * Módulo   : Precio de Combustibles RD
 * ============================================================
 * Muestra los precios vigentes semanales de los combustibles
 * de la República Dominicana, publicados cada viernes por el
 * Ministerio de Industria, Comercio y Mipymes.
 * Incluye histórico paginado y gráfico de tendencias.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ============================================================
// DATOS PRINCIPALES
// ============================================================
$usuario           = currentUser();
$semanaActual      = getCombustiblesSemanaActual();
$preciosVigentes   = getCombustiblesVigentes();

// ── Paginación histórico ──────────────────────────────────────
$paginaHistorico   = max(1, cleanInt($_GET['pag'] ?? 1));
$porPagHistorico   = 8;
$totalSemanas      = countCombustiblesSemanas();
$paginacionHist    = paginate($totalSemanas, $paginaHistorico, $porPagHistorico);
$historicoSemanas  = getCombustiblesSemanas(
    $porPagHistorico,
    $paginacionHist['offset']
);

// ── Detalle de semana para modal (via GET) ────────────────────
$detalleSlug       = cleanInput($_GET['semana'] ?? '');
$detalleSemana     = null;
$detallePrecios    = [];
if (!empty($detalleSlug)) {
    $detalleSemana  = db()->fetchOne(
        "SELECT * FROM combustibles_semanas
         WHERE semana_inicio = ? AND publicado = 1
         LIMIT 1",
        [$detalleSlug]
    );
    if ($detalleSemana) {
        $detallePrecios = getCombustiblesHistorico($detalleSlug);
    }
}

// ── Datos para gráfico Chart.js ───────────────────────────────
$tendenciaData     = getCombustiblesTendencia(8);
$chartLabels       = json_encode($tendenciaData['labels']);
$chartDatasets     = [];
foreach ($tendenciaData['datasets'] as $ds) {
    $chartDatasets[] = [
        'label'           => $ds['label'],
        'data'            => $ds['data'],
        'borderColor'     => $ds['color'],
        'backgroundColor' => $ds['color'] . '18',
        'tension'         => 0.4,
        'fill'            => true,
        'pointRadius'     => 4,
        'pointHoverRadius'=> 7,
        'borderWidth'     => 2,
    ];
}
$chartDatasetsJson = json_encode($chartDatasets);

// ── Estadísticas rápidas ──────────────────────────────────────
$totalSubs         = 0;
$precioMasAlto     = null;
$precioMasBajo     = null;
if (!empty($preciosVigentes)) {
    $precios_arr   = array_column($preciosVigentes, 'precio');
    $precioMasAlto = max($precios_arr);
    $precioMasBajo = min($precios_arr);

    $subidos  = array_filter($preciosVigentes, fn($p) => ($p['variacion'] ?? 0) > 0);
    $bajaron  = array_filter($preciosVigentes, fn($p) => ($p['variacion'] ?? 0) < 0);
    $iguales  = array_filter($preciosVigentes, fn($p) => ($p['variacion'] ?? 0) == 0);
    $cntSubio = count($subidos);
    $cntBajo  = count($bajaron);
    $cntIgual = count($iguales);
}

// ── Anuncios ──────────────────────────────────────────────────
$adSidebar  = getAnuncios('sidebar', null, 1);
$adHeader   = getAnuncios('header',  null, 1);

// ── Noticias relacionadas (categoría Economía) ────────────────
$catEconomia = db()->fetchOne(
    "SELECT id FROM categorias WHERE slug IN ('economia','economia-premium','negocios')
     ORDER BY activa DESC LIMIT 1"
);
$noticiasRelacionadas = [];
if ($catEconomia) {
    $noticiasRelacionadas = db()->fetchAll(
        "SELECT n.titulo, n.slug, n.imagen, n.fecha_publicacion,
                c.nombre AS cat_nombre, c.color AS cat_color, c.slug AS cat_slug
         FROM noticias n
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.categoria_id = ?
           AND n.estado       = 'publicado'
           AND n.fecha_publicacion <= NOW()
         ORDER BY n.fecha_publicacion DESC
         LIMIT 4",
        [(int) $catEconomia['id']]
    );
}

// ── SEO ───────────────────────────────────────────────────────
$siteName          = Config::get('site_nombre', APP_NAME);
$semanaStr         = $semanaActual
    ? 'Semana del ' . formatFechaEsp($semanaActual['semana_inicio'], 'semana')
      . ' al ' . formatFechaEsp($semanaActual['semana_fin'], 'semana')
    : '';
$pageTitle         = "Precio Combustibles RD — {$semanaStr} | {$siteName}";
$pageDescription   = "Consulta los precios actuales de Gasolina Premium, Gasolina Regular,"
                   . " Gasoil Óptimo, GLP y GNV en República Dominicana. Actualizados cada"
                   . " viernes por el Ministerio de Industria, Comercio y Mipymes.";
$pageImage         = defined('IMG_DEFAULT_OG') ? IMG_DEFAULT_OG : '';
$bodyClass         = 'page-combustibles';

require_once __DIR__ . '/includes/header.php';
?>

<?php
// ── Estilos exclusivos de esta página ────────────────────────
?>
<style>
/* ============================================================
   PÁGINA COMBUSTIBLES — Estilos
   ============================================================ */

/* Hero */
.comb-hero {
    background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%);
    padding: 56px 0 48px;
    position: relative;
    overflow: hidden;
}
.comb-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.02'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
}
.comb-hero__inner {
    position: relative;
    z-index: 1;
}
.comb-hero__icon {
    width: 72px;
    height: 72px;
    background: rgba(245,158,11,.15);
    border: 2px solid rgba(245,158,11,.4);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #f59e0b;
    margin-bottom: 20px;
    flex-shrink: 0;
}
.comb-hero__title {
    font-family: var(--font-serif);
    font-size: clamp(1.6rem, 4vw, 2.4rem);
    font-weight: 900;
    color: #fff;
    line-height: 1.15;
    margin-bottom: 10px;
}
.comb-hero__sub {
    font-size: .9rem;
    color: rgba(255,255,255,.65);
    max-width: 600px;
    line-height: 1.6;
}
.comb-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: var(--border-radius-full);
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.comb-badge--vigente {
    background: rgba(245,158,11,.2);
    color: #f59e0b;
    border: 1px solid rgba(245,158,11,.4);
}
.comb-badge--fecha {
    background: rgba(255,255,255,.1);
    color: rgba(255,255,255,.75);
    border: 1px solid rgba(255,255,255,.15);
}
.comb-badge--dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block;
    animation: combPulse 1.8s infinite;
}
@keyframes combPulse {
    0%,100% { opacity: 1; }
    50%      { opacity: .3; }
}

/* Stats bar */
.comb-stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin: 28px 0 0;
}
.comb-stat {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: var(--border-radius-lg);
    padding: 14px 16px;
    text-align: center;
}
.comb-stat__val {
    font-size: 1.35rem;
    font-weight: 800;
    color: #fff;
    display: block;
    font-variant-numeric: tabular-nums;
}
.comb-stat__lbl {
    font-size: .68rem;
    color: rgba(255,255,255,.5);
    text-transform: uppercase;
    letter-spacing: .07em;
    margin-top: 2px;
    display: block;
}
.comb-stat--up   { border-top: 3px solid #ef4444; }
.comb-stat--down { border-top: 3px solid #22c55e; }
.comb-stat--eq   { border-top: 3px solid #6b7280; }
.comb-stat--src  { border-top: 3px solid #f59e0b; }

/* Layout principal */
.comb-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 28px;
    padding: 32px 0;
}
@media (max-width: 991px) {
    .comb-layout { grid-template-columns: 1fr; }
}

/* Sección títulos */
.comb-section {
    margin-bottom: 36px;
}
.comb-section__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 4px solid var(--primary);
    flex-wrap: wrap;
    gap: 10px;
}
.comb-section__title {
    font-family: var(--font-serif);
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}
.comb-section__icon {
    width: 36px; height: 36px;
    background: rgba(230,57,70,.1);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: var(--primary);
    font-size: 1rem;
    flex-shrink: 0;
}

/* Tarjetas de precios */
.comb-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 8px;
}
.comb-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 18px 16px;
    position: relative;
    overflow: hidden;
    transition: transform .2s ease, box-shadow .2s ease;
    border-top: 3px solid var(--card-accent, var(--primary));
}
.comb-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md, 0 8px 24px rgba(0,0,0,.12));
}
.comb-card__bg {
    position: absolute;
    right: -20px; top: -20px;
    font-size: 5rem;
    opacity: .04;
    pointer-events: none;
}
.comb-card__head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}
.comb-card__ico {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.comb-card__name {
    font-size: .82rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.3;
}
.comb-card__unidad {
    font-size: .65rem;
    color: var(--text-muted);
    font-weight: 400;
}
.comb-card__price {
    font-size: 1.5rem;
    font-weight: 900;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    margin-bottom: 8px;
    line-height: 1;
}
.comb-card__price span {
    font-size: .9rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-right: 3px;
}
.comb-card__var {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .72rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: var(--border-radius-full);
}
.comb-card__var--up   { background: #fef2f2; color: #ef4444; }
.comb-card__var--down { background: #f0fdf4; color: #22c55e; }
.comb-card__var--eq   { background: var(--bg-surface-2); color: var(--text-muted); }
[data-theme="dark"] .comb-card__var--up   { background: rgba(239,68,68,.15); }
[data-theme="dark"] .comb-card__var--down { background: rgba(34,197,94,.15); }
[data-theme="dark"] .comb-card__var--eq   { background: rgba(107,114,128,.15); }

/* Tooltip variación */
.comb-card__tooltip {
    font-size: .65rem;
    color: var(--text-muted);
    margin-top: 6px;
}

/* Tabla de precios desktop */
.comb-table-wrap { overflow-x: auto; }
.comb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .875rem;
}
.comb-table th {
    background: var(--bg-surface-2);
    padding: 11px 14px;
    text-align: left;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.comb-table td {
    padding: 13px 14px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.comb-table tr:last-child td { border-bottom: none; }
.comb-table tr:hover td { background: var(--bg-surface-2); }
.comb-table__name {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: var(--text-primary);
}
.comb-table__ico {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem;
    flex-shrink: 0;
}
.comb-price-val {
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    font-size: .95rem;
}
.comb-var-up   { color: #ef4444; font-weight: 700; }
.comb-var-down { color: #22c55e; font-weight: 700; }
.comb-var-eq   { color: var(--text-muted); }
.comb-badge-var {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: .68rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
}

/* Vista switch */
.comb-view-switch {
    display: flex;
    gap: 4px;
    background: var(--bg-surface-2);
    padding: 4px;
    border-radius: 10px;
}
.comb-view-btn {
    padding: 6px 14px;
    border-radius: 7px;
    border: none;
    cursor: pointer;
    font-size: .78rem;
    font-weight: 600;
    color: var(--text-muted);
    background: transparent;
    transition: all .2s ease;
}
.comb-view-btn.active {
    background: var(--bg-surface);
    color: var(--text-primary);
    box-shadow: 0 1px 4px rgba(0,0,0,.1);
}

/* Gráfico */
.comb-chart-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 20px;
    margin-bottom: 8px;
}
.comb-chart-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}
.comb-chart-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: var(--border-radius-full);
    border: 2px solid transparent;
    cursor: pointer;
    font-size: .73rem;
    font-weight: 700;
    transition: all .2s ease;
    background: var(--bg-surface-2);
    color: var(--text-secondary);
}
.comb-chart-toggle.active {
    color: #fff;
    border-color: transparent;
}
.comb-chart-toggle .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* Histórico */
.hist-table { width: 100%; border-collapse: collapse; }
.hist-table th {
    background: var(--bg-surface-2);
    padding: 10px 14px;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border-color);
    text-align: left;
}
.hist-table td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-color);
    font-size: .875rem;
    vertical-align: middle;
}
.hist-table tr:last-child td { border-bottom: none; }
.hist-table tr:hover td      { background: var(--bg-surface-2); transition: background .15s; }
.hist-badge-act {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 10px;
    border-radius: var(--border-radius-full);
    font-size: .65rem;
    font-weight: 800;
    background: rgba(34,197,94,.15);
    color: #22c55e;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.hist-btn-det {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border-radius: var(--border-radius-full);
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    font-size: .75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s ease;
    text-decoration: none;
}
.hist-btn-det:hover {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

/* Modal detalle semana */
.comb-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 1050;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    backdrop-filter: blur(3px);
}
.comb-modal-overlay.open { display: flex; }
.comb-modal {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    width: 100%;
    max-width: 680px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}
.comb-modal__head {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.comb-modal__title {
    font-family: var(--font-serif);
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-primary);
}
.comb-modal__close {
    width: 32px; height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--bg-surface-2);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted);
    font-size: .9rem;
    transition: all .2s ease;
}
.comb-modal__close:hover { background: var(--primary); color: #fff; }
.comb-modal__body {
    padding: 20px 24px;
    overflow-y: auto;
    flex: 1;
}

/* Fuente */
.comb-fuente {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--bg-surface-2);
    border-radius: var(--border-radius);
    font-size: .75rem;
    color: var(--text-muted);
    margin-top: 8px;
}

/* Print */
@media print {
    .comb-hero, .sidebar, .widget,
    .comb-chart-card, .hist-section,
    .comb-view-switch { display: none !important; }
    .comb-table th, .comb-table td { border: 1px solid #ccc !important; }
}

/* Responsive tarjetas */
@media (max-width: 480px) {
    .comb-grid { grid-template-columns: 1fr 1fr; }
    .comb-card__price { font-size: 1.2rem; }
}
</style>

<!-- ============================================================
     HERO
     ============================================================ -->
<div class="comb-hero">
    <div class="container-fluid px-3 px-lg-4">
        <div class="comb-hero__inner">

            <!-- Breadcrumb -->
            <nav aria-label="Ruta" style="display:flex;align-items:center;
                 gap:6px;font-size:.75rem;color:rgba(255,255,255,.5);
                 margin-bottom:20px">
                <a href="<?= APP_URL ?>/index.php"
                   style="color:rgba(255,255,255,.5);text-decoration:none">
                    <i class="bi bi-house-fill"></i> Inicio
                </a>
                <i class="bi bi-chevron-right" style="font-size:.6rem"></i>
                <span style="color:rgba(255,255,255,.8)">Combustibles</span>
            </nav>

            <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
                <div class="comb-hero__icon">
                    <i class="bi bi-fuel-pump-fill"></i>
                </div>
                <div style="flex:1;min-width:260px">
                    <h1 class="comb-hero__title">
                        Precio de los Combustibles
                        <span style="color:#f59e0b">RD</span>
                    </h1>
                    <p class="comb-hero__sub">
                        Precios oficiales publicados cada viernes por el
                        <strong style="color:rgba(255,255,255,.85)">Ministerio de Industria,
                        Comercio y Mipymes</strong> de la República Dominicana.
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px">
                        <?php if ($semanaActual): ?>
                        <span class="comb-badge comb-badge--vigente">
                            <span class="comb-badge--dot"></span>
                            VIGENTE: <?= e($semanaActual['titulo'] ?? $semanaStr) ?>
                        </span>
                        <span class="comb-badge comb-badge--fecha">
                            <i class="bi bi-calendar3"></i>
                            Actualizado: <?= formatFechaEsp($semanaActual['fecha_vigencia']) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Stats bar -->
                    <?php if (!empty($preciosVigentes)): ?>
                    <div class="comb-stats-bar">
                        <div class="comb-stat comb-stat--up">
                            <span class="comb-stat__val" style="color:#ef4444">
                                <?= $cntSubio ?? 0 ?>
                            </span>
                            <span class="comb-stat__lbl">↑ Subieron</span>
                        </div>
                        <div class="comb-stat comb-stat--down">
                            <span class="comb-stat__val" style="color:#22c55e">
                                <?= $cntBajo ?? 0 ?>
                            </span>
                            <span class="comb-stat__lbl">↓ Bajaron</span>
                        </div>
                        <div class="comb-stat comb-stat--eq">
                            <span class="comb-stat__val" style="color:#6b7280">
                                <?= $cntIgual ?? 0 ?>
                            </span>
                            <span class="comb-stat__lbl">= Sin cambio</span>
                        </div>
                        <div class="comb-stat comb-stat--src">
                            <span class="comb-stat__val" style="color:#f59e0b">
                                <?= count($preciosVigentes) ?>
                            </span>
                            <span class="comb-stat__lbl">Combustibles</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     CONTENIDO PRINCIPAL
     ============================================================ -->
<div class="container-fluid px-3 px-lg-4">
<div class="comb-layout">

    <!-- ═══════════════════════════════════════════════════
         COLUMNA PRINCIPAL
    ═══════════════════════════════════════════════════ -->
    <div class="comb-main">

        <?php if (empty($preciosVigentes)): ?>
        <!-- Sin datos -->
        <div style="text-align:center;padding:80px 20px;
                    background:var(--bg-surface);
                    border-radius:var(--border-radius-xl);
                    border:2px dashed var(--border-color);
                    margin-top:24px">
            <i class="bi bi-fuel-pump"
               style="font-size:3rem;color:var(--text-muted);
                      opacity:.3;display:block;margin-bottom:16px"></i>
            <h2 style="font-family:var(--font-serif);color:var(--text-muted)">
                Sin precios disponibles
            </h2>
            <p style="color:var(--text-muted);font-size:.875rem;margin-top:8px">
                Los precios de la semana actual aún no han sido publicados.
            </p>
        </div>
        <?php else: ?>

        <!-- ─────────────────────────────────────────────
             SECCIÓN 1: PRECIOS VIGENTES
        ───────────────────────────────────────────── -->
        <section class="comb-section animate-on-scroll">
            <div class="comb-section__header">
                <h2 class="comb-section__title">
                    <span class="comb-section__icon">
                        <i class="bi bi-fuel-pump-fill"></i>
                    </span>
                    Precios Vigentes
                </h2>
                <div class="comb-view-switch" role="group"
                     aria-label="Cambiar vista">
                    <button class="comb-view-btn active"
                            id="btnViewCards"
                            onclick="switchView('cards')"
                            aria-pressed="true">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        Tarjetas
                    </button>
                    <button class="comb-view-btn"
                            id="btnViewTable"
                            onclick="switchView('table')"
                            aria-pressed="false">
                        <i class="bi bi-table"></i>
                        Tabla
                    </button>
                </div>
            </div>

            <!-- Vista Tarjetas -->
            <div id="viewCards">
                <div class="comb-grid">
                <?php foreach ($preciosVigentes as $cb):
                    $subio  = ($cb['variacion'] ?? 0) > 0;
                    $bajo   = ($cb['variacion'] ?? 0) < 0;
                    $igual  = ($cb['variacion'] ?? 0) == 0;
                    $varAbs = abs((float)($cb['variacion'] ?? 0));
                    $varPct = abs((float)($cb['variacion_pct'] ?? 0));
                    $varClass = $subio ? 'up' : ($bajo ? 'down' : 'eq');
                    $varIcon  = $subio ? 'bi-arrow-up-short' : ($bajo ? 'bi-arrow-down-short' : 'bi-dash');
                    $varLabel = $subio ? "Subió RD$ {$varAbs}" : ($bajo ? "Bajó RD$ {$varAbs}" : "Sin cambio");
                    $varColor = $subio ? '#ef4444' : ($bajo ? '#22c55e' : 'var(--text-muted)');
                ?>
                <article class="comb-card animate-on-scroll"
                         style="--card-accent:<?= e($cb['color']) ?>"
                         title="<?= e($cb['nombre']) ?>: RD$ <?= number_format((float)$cb['precio'], 2) ?> por <?= e($cb['unidad']) ?>">
                    <div class="comb-card__bg">
                        <i class="bi <?= e($cb['icono']) ?>"></i>
                    </div>
                    <div class="comb-card__head">
                        <div class="comb-card__ico"
                             style="background:<?= e($cb['color']) ?>18;
                                    border:1px solid <?= e($cb['color']) ?>30">
                            <i class="bi <?= e($cb['icono']) ?>"
                               style="color:<?= e($cb['color']) ?>"></i>
                        </div>
                        <div>
                            <div class="comb-card__name">
                                <?= e($cb['nombre']) ?>
                            </div>
                            <div class="comb-card__unidad">
                                por <?= e($cb['unidad']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="comb-card__price">
                        <span>RD$</span><?= number_format((float)$cb['precio'], 2) ?>
                    </div>

                    <div class="comb-card__var comb-card__var--<?= $varClass ?>"
                         title="<?= e($varLabel) ?> respecto a la semana anterior">
                        <i class="bi <?= $varIcon ?>"></i>
                        <?php if (!$igual): ?>
                            RD$ <?= number_format($varAbs, 2) ?>
                            <span style="opacity:.7">(<?= number_format($varPct, 2) ?>%)</span>
                        <?php else: ?>
                            Sin cambio
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($cb['precio_anterior'])): ?>
                    <div class="comb-card__tooltip">
                        Semana ant.: RD$ <?= number_format((float)$cb['precio_anterior'], 2) ?>
                    </div>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
                </div>
            </div><!-- /#viewCards -->

            <!-- Vista Tabla -->
            <div id="viewTable" style="display:none">
                <div class="comb-table-wrap">
                    <table class="comb-table" role="table"
                           aria-label="Tabla de precios de combustibles">
                        <thead>
                            <tr>
                                <th>Combustible</th>
                                <th>Precio actual</th>
                                <th>Precio anterior</th>
                                <th>Variación</th>
                                <th>Estado</th>
                                <th>Unidad</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($preciosVigentes as $cb):
                            $subio  = ($cb['variacion'] ?? 0) > 0;
                            $bajo   = ($cb['variacion'] ?? 0) < 0;
                            $igual  = ($cb['variacion'] ?? 0) == 0;
                            $varAbs = abs((float)($cb['variacion'] ?? 0));
                            $varPct = abs((float)($cb['variacion_pct'] ?? 0));
                        ?>
                        <tr>
                            <td>
                                <div class="comb-table__name">
                                    <div class="comb-table__ico"
                                         style="background:<?= e($cb['color']) ?>18;
                                                border:1px solid <?= e($cb['color']) ?>30">
                                        <i class="bi <?= e($cb['icono']) ?>"
                                           style="color:<?= e($cb['color']) ?>"></i>
                                    </div>
                                    <?= e($cb['nombre']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="comb-price-val">
                                    RD$ <?= number_format((float)$cb['precio'], 2) ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted)">
                                <?= !empty($cb['precio_anterior'])
                                    ? 'RD$ ' . number_format((float)$cb['precio_anterior'], 2)
                                    : '—' ?>
                            </td>
                            <td>
                                <?php if ($subio): ?>
                                <span class="comb-var-up">
                                    <i class="bi bi-arrow-up-short"></i>
                                    +RD$ <?= number_format($varAbs, 2) ?>
                                    <small style="font-weight:400">
                                        (+<?= number_format($varPct, 2) ?>%)
                                    </small>
                                </span>
                                <?php elseif ($bajo): ?>
                                <span class="comb-var-down">
                                    <i class="bi bi-arrow-down-short"></i>
                                    -RD$ <?= number_format($varAbs, 2) ?>
                                    <small style="font-weight:400">
                                        (-<?= number_format($varPct, 2) ?>%)
                                    </small>
                                </span>
                                <?php else: ?>
                                <span class="comb-var-eq">
                                    <i class="bi bi-dash"></i> Sin cambio
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = $subio ? 'background:#fef2f2;color:#ef4444'
                                           : ($bajo  ? 'background:#f0fdf4;color:#22c55e'
                                                     : 'background:var(--bg-surface-2);color:var(--text-muted)');
                                $badgeText  = $subio ? '↑ Sube' : ($bajo ? '↓ Baja' : '= Igual');
                                ?>
                                <span class="comb-badge-var"
                                      style="<?= $badgeClass ?>">
                                    <?= $badgeText ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted);font-size:.82rem">
                                <?= e($cb['unidad']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- /#viewTable -->

            <!-- Fuente oficial -->
            <?php if ($semanaActual && !empty($semanaActual['fuente'])): ?>
            <div class="comb-fuente">
                <i class="bi bi-info-circle-fill"
                   style="color:var(--primary);flex-shrink:0"></i>
                <span>
                    <strong>Fuente oficial:</strong>
                    <?= e($semanaActual['fuente']) ?>
                    <?php if (!empty($semanaActual['nota_semanal'])): ?>
                     — <?= e($semanaActual['nota_semanal']) ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Botón imprimir / descargar -->
            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
                <button onclick="window.print()"
                        style="display:inline-flex;align-items:center;gap:6px;
                               padding:8px 18px;border:1px solid var(--border-color);
                               border-radius:var(--border-radius-full);
                               background:transparent;color:var(--text-secondary);
                               font-size:.8rem;font-weight:600;cursor:pointer;
                               transition:all .2s ease"
                        onmouseover="this.style.background='var(--bg-surface-2)'"
                        onmouseout="this.style.background='transparent'">
                    <i class="bi bi-printer-fill"></i>
                    Imprimir tabla
                </button>
                <button onclick="copiarTabla()"
                        id="btnCopiarTabla"
                        style="display:inline-flex;align-items:center;gap:6px;
                               padding:8px 18px;border:1px solid var(--border-color);
                               border-radius:var(--border-radius-full);
                               background:transparent;color:var(--text-secondary);
                               font-size:.8rem;font-weight:600;cursor:pointer;
                               transition:all .2s ease"
                        onmouseover="this.style.background='var(--bg-surface-2)'"
                        onmouseout="this.style.background='transparent'">
                    <i class="bi bi-clipboard2-data"></i>
                    Copiar precios
                </button>
                <a href="<?= APP_URL ?>/combustibles.php?semana=<?= e($semanaActual['semana_inicio'] ?? '') ?>"
                   style="display:inline-flex;align-items:center;gap:6px;
                          padding:8px 18px;
                          background:var(--primary);color:#fff;
                          border-radius:var(--border-radius-full);
                          font-size:.8rem;font-weight:700;
                          text-decoration:none;
                          transition:opacity .2s ease"
                   onmouseover="this.style.opacity='.85'"
                   onmouseout="this.style.opacity='1'">
                    <i class="bi bi-share-fill"></i>
                    Compartir semana
                </a>
            </div>
        </section>

        <!-- ─────────────────────────────────────────────
             SECCIÓN 2: GRÁFICO DE TENDENCIAS
        ───────────────────────────────────────────── -->
        <?php if (!empty($tendenciaData['labels'])): ?>
        <section class="comb-section animate-on-scroll">
            <div class="comb-section__header">
                <h2 class="comb-section__title">
                    <span class="comb-section__icon"
                          style="background:rgba(13,110,253,.1)">
                        <i class="bi bi-graph-up"
                           style="color:#0d6efd"></i>
                    </span>
                    Tendencia de Precios
                </h2>
                <span style="font-size:.75rem;color:var(--text-muted)">
                    Últimas <?= count($tendenciaData['labels']) ?> semanas
                </span>
            </div>

            <div class="comb-chart-card">
                <!-- Filtros por combustible -->
                <div class="comb-chart-filters" id="chartFilters">
                    <?php foreach ($tendenciaData['tipos'] as $idx => $tipo): ?>
                    <button class="comb-chart-toggle active"
                            data-idx="<?= $idx ?>"
                            data-color="<?= e($tipo['color']) ?>"
                            onclick="toggleDataset(this, <?= $idx ?>)"
                            style="background:<?= e($tipo['color']) ?>;
                                   border-color:<?= e($tipo['color']) ?>;
                                   color:#fff">
                        <span class="dot"
                              style="background:rgba(255,255,255,.7)"></span>
                        <?= e($tipo['nombre']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Canvas Chart.js -->
                <div style="position:relative;height:320px">
                    <canvas id="combChart"
                            aria-label="Gráfico de tendencia de precios de combustibles"
                            role="img"></canvas>
                </div>

                <div style="margin-top:10px;font-size:.72rem;
                             color:var(--text-muted);text-align:right">
                    <i class="bi bi-info-circle"></i>
                    Precios en RD$ por <?= e($preciosVigentes[0]['unidad'] ?? 'galón') ?>
                    — Haz clic en los botones para mostrar/ocultar combustibles
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php endif; // fin if !empty($preciosVigentes) ?>

        <!-- ─────────────────────────────────────────────
             SECCIÓN 3: HISTÓRICO DE SEMANAS
        ───────────────────────────────────────────── -->
        <?php if (!empty($historicoSemanas)): ?>
        <section class="comb-section hist-section animate-on-scroll">
            <div class="comb-section__header">
                <h2 class="comb-section__title">
                    <span class="comb-section__icon"
                          style="background:rgba(107,114,128,.1)">
                        <i class="bi bi-clock-history"
                           style="color:#6b7280"></i>
                    </span>
                    Histórico de Semanas
                </h2>
                <span style="font-size:.75rem;color:var(--text-muted)">
                    <?= $totalSemanas ?> semana<?= $totalSemanas !== 1 ? 's' : '' ?> publicada<?= $totalSemanas !== 1 ? 's' : '' ?>
                </span>
            </div>

            <div style="background:var(--bg-surface);
                        border:1px solid var(--border-color);
                        border-radius:var(--border-radius-lg);
                        overflow:hidden">
                <div style="overflow-x:auto">
                <table class="hist-table" role="table"
                       aria-label="Histórico de precios semanales">
                    <thead>
                        <tr>
                            <th>Semana</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th class="d-none d-md-table-cell">Fuente</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historicoSemanas as $sem): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;color:var(--text-primary);
                                         font-size:.875rem">
                                <?= e($sem['titulo'] ?? 'Semana ' . $sem['semana_inicio']) ?>
                            </div>
                            <div style="font-size:.72rem;color:var(--text-muted);
                                         margin-top:2px">
                                <?= formatFechaEsp($sem['semana_inicio'], 'semana') ?>
                                al
                                <?= formatFechaEsp($sem['semana_fin'], 'semana') ?>
                            </div>
                        </td>
                        <td style="font-size:.82rem;color:var(--text-secondary)">
                            <i class="bi bi-calendar-week"
                               style="color:var(--primary)"></i>
                            <?= formatFechaEsp($sem['fecha_vigencia'], 'medio') ?>
                        </td>
                        <td>
                            <?php if ($sem['es_actual']): ?>
                            <span class="hist-badge-act">
                                <span style="width:5px;height:5px;border-radius:50%;
                                             background:currentColor;display:inline-block"></span>
                                ACTUAL
                            </span>
                            <?php else: ?>
                            <span style="font-size:.72rem;color:var(--text-muted)">
                                Histórico
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"
                            style="font-size:.75rem;color:var(--text-muted);
                                   max-width:180px;overflow:hidden;
                                   text-overflow:ellipsis;white-space:nowrap">
                            <?= e($sem['fuente'] ?? '') ?>
                        </td>
                        <td>
                            <button class="hist-btn-det"
                                    onclick="abrirDetalle('<?= e($sem['semana_inicio']) ?>',
                                             '<?= e(addslashes($sem['titulo'] ?? 'Detalle')) ?>')"
                                    aria-label="Ver detalle de <?= e($sem['titulo'] ?? 'la semana') ?>">
                                <i class="bi bi-eye-fill"></i>
                                Ver precios
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Paginación histórico -->
                <?php if ($paginacionHist['totalPaginas'] > 1): ?>
                <div style="padding:16px 20px;border-top:1px solid var(--border-color);
                             display:flex;justify-content:center;gap:6px;flex-wrap:wrap">
                    <?php if ($paginacionHist['hasPrev']): ?>
                    <a href="?pag=<?= $paginaHistorico - 1 ?>"
                       style="display:inline-flex;align-items:center;gap:4px;
                              padding:7px 14px;border:1px solid var(--border-color);
                              border-radius:var(--border-radius-full);
                              color:var(--text-secondary);font-size:.8rem;
                              font-weight:600;text-decoration:none;
                              transition:all .2s ease"
                       onmouseover="this.style.background='var(--bg-surface-2)'"
                       onmouseout="this.style.background='transparent'">
                        <i class="bi bi-chevron-left"></i> Anterior
                    </a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $paginacionHist['totalPaginas']; $p++): ?>
                    <a href="?pag=<?= $p ?>"
                       style="display:inline-flex;align-items:center;justify-content:center;
                              width:36px;height:36px;
                              border-radius:var(--border-radius-full);
                              font-size:.82rem;font-weight:700;
                              text-decoration:none;
                              transition:all .2s ease;
                              <?= $p === $paginaHistorico
                                  ? 'background:var(--primary);color:#fff;border:1px solid var(--primary)'
                                  : 'border:1px solid var(--border-color);color:var(--text-secondary)' ?>">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($paginacionHist['hasNext']): ?>
                    <a href="?pag=<?= $paginaHistorico + 1 ?>"
                       style="display:inline-flex;align-items:center;gap:4px;
                              padding:7px 14px;border:1px solid var(--border-color);
                              border-radius:var(--border-radius-full);
                              color:var(--text-secondary);font-size:.8rem;
                              font-weight:600;text-decoration:none;
                              transition:all .2s ease"
                       onmouseover="this.style.background='var(--bg-surface-2)'"
                       onmouseout="this.style.background='transparent'">
                        Siguiente <i class="bi bi-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </section>
        <?php endif; ?>

        <!-- Nota informativa -->
        <div style="background:var(--bg-surface-2);
                    border-left:4px solid #f59e0b;
                    border-radius:0 var(--border-radius) var(--border-radius) 0;
                    padding:16px 20px;
                    margin-top:8px;
                    margin-bottom:32px">
            <div style="display:flex;gap:12px;align-items:flex-start">
                <i class="bi bi-info-circle-fill"
                   style="color:#f59e0b;font-size:1.1rem;flex-shrink:0;margin-top:2px"></i>
                <div>
                    <strong style="color:var(--text-primary);font-size:.875rem">
                        ¿Cuándo se actualizan los precios?
                    </strong>
                    <p style="font-size:.82rem;color:var(--text-muted);
                               margin:6px 0 0;line-height:1.6">
                        Los precios de los combustibles en la República Dominicana se
                        actualizan <strong>cada viernes</strong> mediante resolución del
                        Ministerio de Industria, Comercio y Mipymes (MICM). Las nuevas
                        tarifas entran en vigencia a partir del sábado siguiente.
                    </p>
                </div>
            </div>
        </div>

    </div><!-- /.comb-main -->

    <!-- ═══════════════════════════════════════════════════
         SIDEBAR
    ═══════════════════════════════════════════════════ -->
    <aside class="sidebar" role="complementary"
           aria-label="Información adicional">

        <!-- Widget: Anuncio sidebar -->
        <?php if (!empty($adSidebar) && Config::bool('ads_activos_global') && Config::bool('ads_sidebar')): ?>
        <div class="widget animate-on-scroll">
            <?= renderAnuncio($adSidebar[0]) ?>
        </div>
        <?php endif; ?>

        <!-- Widget: Combustible más alto / más bajo -->
        <?php if (!empty($preciosVigentes)): ?>
        <div class="widget animate-on-scroll">
            <div class="widget__header">
                <h3 class="widget__title">
                    <i class="bi bi-bar-chart-fill"
                       style="color:var(--primary)"></i>
                    Esta Semana
                </h3>
            </div>
            <div class="widget__body">
                <!-- Más caro -->
                <?php
                $maxComb = null;
                $minComb = null;
                foreach ($preciosVigentes as $c) {
                    if (!$maxComb || $c['precio'] > $maxComb['precio']) $maxComb = $c;
                    if (!$minComb || $c['precio'] < $minComb['precio']) $minComb = $c;
                }
                ?>
                <div style="display:flex;flex-direction:column;gap:10px">
                    <?php if ($maxComb): ?>
                    <div style="padding:12px;background:rgba(239,68,68,.05);
                                border:1px solid rgba(239,68,68,.15);
                                border-radius:var(--border-radius);
                                border-left:3px solid #ef4444">
                        <div style="font-size:.65rem;color:#ef4444;
                                     font-weight:700;text-transform:uppercase;
                                     letter-spacing:.08em;margin-bottom:5px">
                            <i class="bi bi-arrow-up-circle-fill"></i> Más caro
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <i class="bi <?= e($maxComb['icono']) ?>"
                               style="color:<?= e($maxComb['color']) ?>;font-size:1.1rem"></i>
                            <div>
                                <div style="font-weight:700;font-size:.82rem;
                                             color:var(--text-primary)">
                                    <?= e($maxComb['nombre']) ?>
                                </div>
                                <div style="font-weight:800;font-size:1rem;
                                             color:#ef4444">
                                    RD$ <?= number_format((float)$maxComb['precio'], 2) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($minComb): ?>
                    <div style="padding:12px;background:rgba(34,197,94,.05);
                                border:1px solid rgba(34,197,94,.15);
                                border-radius:var(--border-radius);
                                border-left:3px solid #22c55e">
                        <div style="font-size:.65rem;color:#22c55e;
                                     font-weight:700;text-transform:uppercase;
                                     letter-spacing:.08em;margin-bottom:5px">
                            <i class="bi bi-arrow-down-circle-fill"></i> Más económico
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <i class="bi <?= e($minComb['icono']) ?>"
                               style="color:<?= e($minComb['color']) ?>;font-size:1.1rem"></i>
                            <div>
                                <div style="font-weight:700;font-size:.82rem;
                                             color:var(--text-primary)">
                                    <?= e($minComb['nombre']) ?>
                                </div>
                                <div style="font-weight:800;font-size:1rem;
                                             color:#22c55e">
                                    RD$ <?= number_format((float)$minComb['precio'], 2) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Widget: Noticias de economía relacionadas -->
        <?php if (!empty($noticiasRelacionadas)): ?>
        <div class="widget animate-on-scroll">
            <div class="widget__header">
                <h3 class="widget__title">
                    <i class="bi bi-newspaper"
                       style="color:var(--primary)"></i>
                    Noticias Económicas
                </h3>
            </div>
            <div class="widget__body" style="padding:0 var(--space-4)">
                <?php foreach ($noticiasRelacionadas as $nr): ?>
                <div style="padding:12px 0;
                             border-bottom:1px solid var(--border-color);
                             display:flex;gap:10px;align-items:flex-start">
                    <?php if ($nr['imagen']): ?>
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($nr['slug']) ?>"
                       style="flex-shrink:0">
                        <img src="<?= e(getImageUrl($nr['imagen'])) ?>"
                             alt="<?= e($nr['titulo']) ?>"
                             style="width:60px;height:45px;object-fit:cover;
                                    border-radius:var(--border-radius-sm)"
                             loading="lazy">
                    </a>
                    <?php endif; ?>
                    <div style="flex:1;min-width:0">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($nr['slug']) ?>"
                           style="display:block;font-size:.78rem;font-weight:700;
                                  color:var(--text-primary);text-decoration:none;
                                  line-height:1.3;margin-bottom:4px;
                                  transition:color .15s ease"
                           onmouseover="this.style.color='var(--primary)'"
                           onmouseout="this.style.color='var(--text-primary)'">
                            <?= e(truncateChars($nr['titulo'], 65)) ?>
                        </a>
                        <span style="font-size:.68rem;color:var(--text-muted)">
                            <i class="bi bi-clock"></i>
                            <?= timeAgo($nr['fecha_publicacion']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Widget: ¿Sabías que? -->
        <div class="widget animate-on-scroll">
            <div class="widget__header"
                 style="background:linear-gradient(135deg,#f59e0b18,transparent)">
                <h3 class="widget__title">
                    <i class="bi bi-lightbulb-fill"
                       style="color:#f59e0b"></i>
                    ¿Sabías que?
                </h3>
            </div>
            <div class="widget__body">
                <div style="display:flex;flex-direction:column;gap:14px">
                    <div style="display:flex;gap:10px;align-items:flex-start">
                        <i class="bi bi-fuel-pump"
                           style="color:#f59e0b;font-size:1.1rem;flex-shrink:0;margin-top:2px"></i>
                        <p style="font-size:.8rem;color:var(--text-secondary);
                                   line-height:1.55;margin:0">
                            La Gasolina Premium tiene mayor octanaje, lo que la hace
                            más eficiente para motores de alto rendimiento, aunque
                            su precio siempre es superior a la Regular.
                        </p>
                    </div>
                    <div style="display:flex;gap:10px;align-items:flex-start">
                        <i class="bi bi-droplet-fill"
                           style="color:#198754;font-size:1.1rem;flex-shrink:0;margin-top:2px"></i>
                        <p style="font-size:.8rem;color:var(--text-secondary);
                                   line-height:1.55;margin:0">
                            El Gasoil (diesel) es el combustible predominante en el
                            transporte de carga pesada y los vehículos de trabajo
                            en República Dominicana.
                        </p>
                    </div>
                    <div style="display:flex;gap:10px;align-items:flex-start">
                        <i class="bi bi-radioactive"
                           style="color:#0d6efd;font-size:1.1rem;flex-shrink:0;margin-top:2px"></i>
                        <p style="font-size:.8rem;color:var(--text-secondary);
                                   line-height:1.55;margin:0">
                            El GLP (Gas Licuado de Petróleo) es usado ampliamente
                            en vehículos adaptados y resulta hasta un 50% más
                            económico que la gasolina regular.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </aside><!-- /.sidebar -->

</div><!-- /.comb-layout -->
</div><!-- /.container-fluid -->

<!-- ============================================================
     MODAL DETALLE DE SEMANA
     ============================================================ -->
<div class="comb-modal-overlay"
     id="modalDetalle"
     role="dialog"
     aria-modal="true"
     aria-labelledby="modalDetalleTitle"
     onclick="if(event.target===this) cerrarDetalle()">
    <div class="comb-modal">
        <div class="comb-modal__head">
            <h2 class="comb-modal__title"
                id="modalDetalleTitle">
                <i class="bi bi-fuel-pump-fill"
                   style="color:var(--primary)"></i>
                <span id="modalDetalleTitleText">Precios de la semana</span>
            </h2>
            <button class="comb-modal__close"
                    onclick="cerrarDetalle()"
                    aria-label="Cerrar modal">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="comb-modal__body" id="modalDetalleBody">
            <!-- Contenido cargado via AJAX -->
            <div style="text-align:center;padding:40px 0;
                        color:var(--text-muted)">
                <div style="width:36px;height:36px;
                             border:3px solid var(--primary);
                             border-top-color:transparent;
                             border-radius:50%;
                             animation:spin .7s linear infinite;
                             margin:0 auto 12px">
                </div>
                Cargando precios...
            </div>
        </div>
    </div>
</div>

<?php
// ── Serializar datos de precios actuales para copiar al portapapeles ──
$preciosParaCopiar = '';
if (!empty($preciosVigentes) && $semanaActual) {
    $lineas = ["Precios Combustibles RD — " . ($semanaActual['titulo'] ?? '')];
    $lineas[] = str_repeat('─', 45);
    foreach ($preciosVigentes as $cb) {
        $lineas[] = sprintf(
            "%-35s RD$ %s",
            $cb['nombre'],
            number_format((float)$cb['precio'], 2)
        );
    }
    $lineas[] = str_repeat('─', 45);
    $lineas[] = "Fuente: " . ($semanaActual['fuente'] ?? '');
    $preciosParaCopiar = e(implode("\n", $lineas));
}
?>

<!-- ============================================================
     SCRIPTS
     ============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"
        integrity="sha256-oVuCGGXEjIs7bBFiHNMSHrVyh5t7Ue2Hh5l0+i0p+E="
        crossorigin="anonymous"
        referrerpolicy="no-referrer"
        defer></script>

<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
/* ============================================================
   COMBUSTIBLES.PHP — Scripts
   ============================================================ */
(function () {
    'use strict';

    // ── Detectar modo oscuro ──────────────────────────────────
    const isDark = () =>
        document.documentElement.getAttribute('data-theme') === 'dark' ||
        (document.documentElement.getAttribute('data-theme') === 'auto' &&
         window.matchMedia('(prefers-color-scheme: dark)').matches);

    // ── Switch de vista (tarjetas / tabla) ──────────────────
    window.switchView = function (view) {
        const cards = document.getElementById('viewCards');
        const table = document.getElementById('viewTable');
        const btnC  = document.getElementById('btnViewCards');
        const btnT  = document.getElementById('btnViewTable');

        if (view === 'cards') {
            cards.style.display = '';
            table.style.display = 'none';
            btnC.classList.add('active');
            btnT.classList.remove('active');
            btnC.setAttribute('aria-pressed', 'true');
            btnT.setAttribute('aria-pressed', 'false');
        } else {
            cards.style.display = 'none';
            table.style.display = '';
            btnT.classList.add('active');
            btnC.classList.remove('active');
            btnT.setAttribute('aria-pressed', 'true');
            btnC.setAttribute('aria-pressed', 'false');
        }
        localStorage.setItem('combView', view);
    };

    // Restaurar vista preferida
    const savedView = localStorage.getItem('combView');
    if (savedView === 'table') switchView('table');

    // ── Copiar tabla al portapapeles ──────────────────────────
    window.copiarTabla = function () {
        const texto = <?= json_encode(implode("\n", array_map(function($cb) {
            return $cb['nombre'] . ': RD$ ' . number_format((float)$cb['precio'], 2);
        }, $preciosVigentes))) ?>;
        const titulo = <?= json_encode(
            'Precios Combustibles RD — ' . ($semanaActual['titulo'] ?? $semanaStr)
        ) ?>;
        const fuente = <?= json_encode(
            'Fuente: ' . ($semanaActual['fuente'] ?? 'Ministerio de Industria, Comercio y Mipymes')
        ) ?>;
        const contenido = titulo + '\n' + '─'.repeat(40) + '\n' + texto + '\n' + fuente;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(contenido).then(() => {
                const btn = document.getElementById('btnCopiarTabla');
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> ¡Copiado!';
                btn.style.color = '#22c55e';
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.style.color = '';
                }, 2500);
            });
        } else {
            const ta = document.createElement('textarea');
            ta.value = contenido;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
    };

    // ── Modal detalle de semana ───────────────────────────────
    window.abrirDetalle = function (semanaInicio, titulo) {
        const overlay = document.getElementById('modalDetalle');
        const titleEl = document.getElementById('modalDetalleTitleText');
        const bodyEl  = document.getElementById('modalDetalleBody');

        titleEl.textContent = titulo;
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';

        // Loader
        bodyEl.innerHTML = `
            <div style="text-align:center;padding:40px 0;color:var(--text-muted)">
                <div style="width:36px;height:36px;border:3px solid var(--primary);
                             border-top-color:transparent;border-radius:50%;
                             animation:spin .7s linear infinite;margin:0 auto 12px"></div>
                Cargando precios...
            </div>`;

        fetch('<?= APP_URL ?>/combustibles.php?semana=' + encodeURIComponent(semanaInicio), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(html => {
            // Parsear la respuesta para extraer el contenido del modal inline
            const parser = new DOMParser();
            const doc    = parser.parseFromString(html, 'text/html');
            const tabla  = doc.querySelector('.comb-table');

            if (tabla) {
                // Clonar y mostrar solo la tabla de la semana
                const wrap = document.createElement('div');
                wrap.style.cssText = 'overflow-x:auto';
                wrap.appendChild(tabla.cloneNode(true));
                bodyEl.innerHTML = '';
                bodyEl.appendChild(wrap);
            } else {
                bodyEl.innerHTML = `
                    <div style="text-align:center;padding:30px;color:var(--text-muted)">
                        <i class="bi bi-exclamation-circle" style="font-size:2rem"></i>
                        <p style="margin-top:10px">No se encontraron datos para esta semana.</p>
                    </div>`;
            }
        })
        .catch(() => {
            bodyEl.innerHTML = `
                <div style="text-align:center;padding:30px;color:var(--text-muted)">
                    <i class="bi bi-wifi-off" style="font-size:2rem"></i>
                    <p style="margin-top:10px">Error al cargar los datos.</p>
                </div>`;
        });
    };

    // Cargar detalle inline si viene en la URL (?semana=...)
    <?php if ($detalleSemana && !empty($detallePrecios)): ?>
    document.addEventListener('DOMContentLoaded', function () {
        // Mostrar los precios de la semana solicitada en el modal
        const overlay = document.getElementById('modalDetalle');
        const titleEl = document.getElementById('modalDetalleTitleText');
        const bodyEl  = document.getElementById('modalDetalleBody');
        titleEl.textContent = <?= json_encode($detalleSemana['titulo'] ?? 'Precios de la semana') ?>;
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        bodyEl.innerHTML = <?= json_encode(buildDetalleModalHTML($detallePrecios, $detalleSemana)) ?>;
    });
    <?php endif; ?>

    window.cerrarDetalle = function () {
        document.getElementById('modalDetalle').classList.remove('open');
        document.body.style.overflow = '';
    };

    // Cerrar modal con Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') cerrarDetalle();
    });

    // ── Chart.js — Gráfico de tendencias ─────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const canvas = document.getElementById('combChart');
        if (!canvas) return;

        const labels   = <?= $chartLabels ?>;
        const datasets = <?= $chartDatasetsJson ?>;

        if (!labels || labels.length === 0) return;

        const dark     = isDark();
        const gridColor = dark
            ? 'rgba(255,255,255,0.06)'
            : 'rgba(0,0,0,0.06)';
        const tickColor = dark
            ? 'rgba(255,255,255,0.45)'
            : 'rgba(0,0,0,0.45)';

        window._combChart = new Chart(canvas, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: dark
                            ? 'rgba(30,41,59,0.98)'
                            : 'rgba(255,255,255,0.98)',
                        titleColor: dark ? '#e2e8f0' : '#1e293b',
                        bodyColor:  dark ? '#94a3b8' : '#475569',
                        borderColor: dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: ctx => {
                                const v = ctx.parsed.y;
                                if (v === null) return ctx.dataset.label + ': N/D';
                                return ctx.dataset.label +
                                       ': RD$ ' + v.toLocaleString('es-DO',
                                           { minimumFractionDigits: 2,
                                             maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: {
                            color: tickColor,
                            font: { size: 11 }
                        }
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: {
                            color: tickColor,
                            font: { size: 11 },
                            callback: v => 'RD$ ' + v.toLocaleString('es-DO',
                                { minimumFractionDigits: 0,
                                  maximumFractionDigits: 0 })
                        }
                    }
                }
            }
        });
    });

    // ── Toggle de datasets en el gráfico ─────────────────────
    window.toggleDataset = function (btn, idx) {
        if (!window._combChart) return;
        const chart = window._combChart;
        const meta  = chart.getDatasetMeta(idx);
        const color = btn.getAttribute('data-color');

        if (btn.classList.contains('active')) {
            meta.hidden = true;
            btn.classList.remove('active');
            btn.style.background   = 'var(--bg-surface-2)';
            btn.style.color        = 'var(--text-muted)';
            btn.style.borderColor  = 'var(--border-color)';
        } else {
            meta.hidden = false;
            btn.classList.add('active');
            btn.style.background  = color;
            btn.style.color       = '#fff';
            btn.style.borderColor = color;
        }
        chart.update();
    };

})();
</script>

<?php
// ── Función para construir HTML del modal de detalle ──────────
function buildDetalleModalHTML(array $precios, array $semana): string
{
    $html = '<div style="margin-bottom:14px">';
    $html .= '<span style="font-size:.8rem;color:var(--text-muted)">';
    $html .= '<i class="bi bi-calendar3"></i> ';
    $html .= formatFechaEsp($semana['semana_inicio'], 'medio')
           . ' — '
           . formatFechaEsp($semana['semana_fin'], 'medio');
    $html .= '</span></div>';

    $html .= '<div style="overflow-x:auto"><table class="comb-table" style="font-size:.82rem">';
    $html .= '<thead><tr>
        <th>Combustible</th>
        <th>Precio</th>
        <th>Anterior</th>
        <th>Variación</th>
    </tr></thead><tbody>';

    foreach ($precios as $cb) {
        $subio  = ($cb['variacion'] ?? 0) > 0;
        $bajo   = ($cb['variacion'] ?? 0) < 0;
        $varAbs = abs((float)($cb['variacion'] ?? 0));
        $varPct = abs((float)($cb['variacion_pct'] ?? 0));

        if ($subio) {
            $varHtml = '<span style="color:#ef4444;font-weight:700">'
                     . '<i class="bi bi-arrow-up-short"></i>'
                     . '+RD$ ' . number_format($varAbs, 2)
                     . ' (+' . number_format($varPct, 2) . '%)</span>';
        } elseif ($bajo) {
            $varHtml = '<span style="color:#22c55e;font-weight:700">'
                     . '<i class="bi bi-arrow-down-short"></i>'
                     . '-RD$ ' . number_format($varAbs, 2)
                     . ' (-' . number_format($varPct, 2) . '%)</span>';
        } else {
            $varHtml = '<span style="color:var(--text-muted)">'
                     . '<i class="bi bi-dash"></i> Sin cambio</span>';
        }

        $html .= '<tr>';
        $html .= '<td style="display:flex;align-items:center;gap:8px">'
               . '<span style="width:28px;height:28px;border-radius:7px;'
               . 'background:' . htmlspecialchars($cb['color']) . '18;'
               . 'display:flex;align-items:center;justify-content:center;flex-shrink:0">'
               . '<i class="bi ' . htmlspecialchars($cb['icono']) . '" '
               . 'style="color:' . htmlspecialchars($cb['color']) . ';font-size:.82rem"></i></span>'
               . '<span style="font-weight:600;color:var(--text-primary)">'
               . htmlspecialchars($cb['nombre']) . '</span></td>';
        $html .= '<td><span style="font-weight:800;font-variant-numeric:tabular-nums">'
               . 'RD$ ' . number_format((float)$cb['precio'], 2) . '</span></td>';
        $html .= '<td style="color:var(--text-muted)">'
               . (!empty($cb['precio_anterior'])
                   ? 'RD$ ' . number_format((float)$cb['precio_anterior'], 2)
                   : '—') . '</td>';
        $html .= '<td>' . $varHtml . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    if (!empty($semana['fuente'])) {
        $html .= '<div style="margin-top:12px;padding:8px 12px;'
               . 'background:var(--bg-surface-2);border-radius:var(--border-radius);'
               . 'font-size:.72rem;color:var(--text-muted)">'
               . '<i class="bi bi-info-circle"></i> '
               . '<strong>Fuente:</strong> ' . htmlspecialchars($semana['fuente'])
               . '</div>';
    }

    return $html;
}
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>