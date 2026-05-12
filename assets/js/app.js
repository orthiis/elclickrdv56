/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — JavaScript Principal
 * ============================================================
 * Archivo : assets/js/app.js
 * Versión : 2.0.0
 * ============================================================
 * MÓDULOS:
 *  01. Core (init, helpers, API)
 *  02. Toast / Notificaciones UI
 *  03. Confirm Dialog
 *  04. Dark Mode
 *  05. Scroll Infinito
 *  06. Comentarios
 *  07. Reacciones
 *  08. Encuestas
 *  09. Favoritos
 *  10. Compartir
 *  11. TTS (Text-To-Speech)
 *  12. Live Blog
 *  13. Videos
 *  14. Búsqueda Avanzada
 *  15. Lazy Loading
 *  16. Animaciones Scroll
 *  17. Admin Table
 *  18. Charts (Chart.js)
 *  19. Notificaciones push UI
 *  20. Mini preview hover
 *  21. Modo Lectura
 *  22. Seguir usuario/categoría
 *  23. Reportar contenido
 *  24. PWA helpers
 *  25. Utilidades globales
 * ============================================================
 */

'use strict';

const PDApp = (function () {

    // ── Configuración global ──────────────────────────────────
    const CONFIG = {
        apiUrl:      window.APP?.url + '/ajax/handler.php',
        csrfToken:   window.APP?.csrfToken ?? '',
        userId:      window.APP?.userId    ?? null,
        isPremium:   window.APP?.isPremium ?? false,
        darkMode:    window.APP?.darkMode  ?? 'auto',
        scrollTrack: window.APP?.analyticsScroll  ?? false,
        heatmap:     window.APP?.analyticsHeatmap ?? false,
    };

    // ── Estado global ─────────────────────────────────────────
    const STATE = {
        currentPage:     1,
        loadingMore:     false,
        hasMoreNews:     true,
        livePoolInterval:null,
        lastLiveUpdateId:0,
        ttsActive:       false,
    };

    // ============================================================
    // 01. CORE — Inicialización y helpers
    // ============================================================

    function init() {
        // Módulos siempre activos
        initLazyImages();
        initAnimations();
        initTooltips();
        initMiniPreviews();

        // Módulos condicionales por página
        const page = document.body.dataset.page;

        if (page === 'home' || page === 'categoria') {
            initInfiniteScroll();
        }

        if (page === 'noticia') {
            initReactions();
            initComments();
            initPoll();
            initShare();
            initTTS();
            initReadingMode();
            initReadingProgress();
        }

        if (page === 'live') {
            initLiveBlog();
        }

        if (page === 'buscar') {
            initAdvancedSearch();
        }

        if (page === 'perfil') {
            initProfile();
        }

        if (page === 'admin') {
            initAdminTable();
            initAdminCharts();
        }

        // Siempre
        initNotificationsUI();
        initGlobalForms();
        updateCsrfToken();
    }

    // ── Fetch helper centralizado ─────────────────────────────
    async function apiPost(action, data = {}) {
        try {
            const response = await fetch(CONFIG.apiUrl, {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     CONFIG.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ action, ...data }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return await response.json();
        } catch (err) {
            console.error(`[PDApp] API error (${action}):`, err);
            return { success: false, message: 'Error de conexión.' };
        }
    }

    // ── Fetch GET helper ──────────────────────────────────────
    async function apiGet(action, params = {}) {
        const qs  = new URLSearchParams({ action, ...params }).toString();
        try {
            const response = await fetch(`${CONFIG.apiUrl}?${qs}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            return await response.json();
        } catch (err) {
            return { success: false, message: 'Error de conexión.' };
        }
    }

    // ── Actualizar CSRF periódicamente ────────────────────────
    async function updateCsrfToken() {
        setInterval(async () => {
            const data = await apiPost('refresh_csrf');
            if (data.csrf_token) {
                CONFIG.csrfToken = data.csrf_token;
                window.APP.csrfToken = data.csrf_token;
                // Actualizar campos ocultos en formularios
                document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
                    input.value = data.csrf_token;
                });
            }
        }, 25 * 60 * 1000); // cada 25 minutos
    }

    // ── Debounce ──────────────────────────────────────────────
    function debounce(fn, delay = 300) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), delay);
        };
    }

    // ── Throttle ──────────────────────────────────────────────
    function throttle(fn, limit = 200) {
        let inThrottle;
        return (...args) => {
            if (!inThrottle) {
                fn(...args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    // ── Escape HTML ───────────────────────────────────────────
    function escapeHtml(str) {
        const map = { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' };
        return String(str).replace(/[&<>"']/g, m => map[m]);
    }

    // ── Formatear número ──────────────────────────────────────
    function formatNumber(n) {
        if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
        if (n >= 1_000)     return (n / 1_000).toFixed(1) + 'K';
        return n.toLocaleString('es-DO');
    }

    // ── Tiempo relativo ───────────────────────────────────────
    function timeAgo(dateStr) {
        const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
        if (diff < 60)    return 'hace un momento';
        if (diff < 3600)  return `hace ${Math.floor(diff/60)} min`;
        if (diff < 86400) return `hace ${Math.floor(diff/3600)} h`;
        if (diff < 604800)return `hace ${Math.floor(diff/86400)} días`;
        return new Date(dateStr).toLocaleDateString('es-DO');
    }

    // ── Verificar login ───────────────────────────────────────
    function requireLogin(msg = 'Debes iniciar sesión para continuar.') {
        if (!CONFIG.userId) {
            showToast(msg, 'warning');
            setTimeout(() => {
                window.location.href = window.APP.url + '/login.php?redirect=' +
                    encodeURIComponent(window.location.href);
            }, 1200);
            return false;
        }
        return true;
    }

    // ============================================================
    // 02. TOAST / NOTIFICACIONES UI
    // ============================================================

    function showToast(message, type = 'info', duration = 4000) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const icons = {
            success: 'bi-check-circle-fill',
            error:   'bi-exclamation-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            info:    'bi-info-circle-fill',
        };

        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.innerHTML = `
            <i class="bi ${icons[type] || icons.info} toast-icon"></i>
            <span>${escapeHtml(message)}</span>
            <button class="toast-close" aria-label="Cerrar">
                <i class="bi bi-x-lg"></i>
            </button>
        `;

        container.appendChild(toast);

        // Cerrar al hacer clic
        toast.querySelector('.toast-close').addEventListener('click', () => removeToast(toast));

        // Auto-dismiss
        const timer = setTimeout(() => removeToast(toast), duration);
        toast.dataset.timer = timer;

        // Pausar al hacer hover
        toast.addEventListener('mouseenter', () => clearTimeout(parseInt(toast.dataset.timer)));
        toast.addEventListener('mouseleave', () => {
            toast.dataset.timer = setTimeout(() => removeToast(toast), 2000);
        });
    }

    function removeToast(toast) {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(30px)';
        toast.style.transition = '300ms ease';
        setTimeout(() => toast.remove(), 300);
    }

    // ============================================================
    // 03. CONFIRM DIALOG
    // ============================================================

    function showConfirm({ title = '¿Estás seguro?', text = '', confirmText = 'Confirmar', cancelText = 'Cancelar', type = 'warning' }) {
        return new Promise((resolve) => {
            if (window.Swal) {
                Swal.fire({
                    title,
                    text,
                    icon:               type,
                    showCancelButton:   true,
                    confirmButtonText:  confirmText,
                    cancelButtonText:   cancelText,
                    confirmButtonColor: '#e63946',
                    cancelButtonColor:  '#6c757d',
                    reverseButtons:     true,
                }).then(resolve);
                return;
            }
            // Fallback nativo
            resolve({ isConfirmed: confirm(`${title}\n${text}`) });
        });
    }

    // ============================================================
    // 04. DARK MODE
    // ============================================================

    function applyDarkMode(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('pd_theme', theme);

        const icon = document.getElementById('darkModeIcon');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }

        const drawerSwitch = document.getElementById('drawerDarkSwitch');
        drawerSwitch?.classList.toggle('active', theme === 'dark');

        // Disparar evento custom
        document.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme } }));
    }

    // ============================================================
    // 05. SCROLL INFINITO
    // ============================================================

    function initInfiniteScroll() {
        const grid      = document.getElementById('newsGrid');
        const loader    = document.getElementById('infiniteLoader');
        const loadBtn   = document.getElementById('loadMoreBtn');

        if (!grid) return;

        const categoriaId = grid.dataset.categoria ?? '';
        const orden       = grid.dataset.orden      ?? 'recientes';

        // Observer para auto-cargar
        if ('IntersectionObserver' in window && loader) {
            const observer = new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting && !STATE.loadingMore && STATE.hasMoreNews) {
                    loadMoreNews(grid, loader, categoriaId, orden);
                }
            }, { rootMargin: '300px 0px' });

            observer.observe(loader);
        }

        // Botón manual como fallback
        loadBtn?.addEventListener('click', () => {
            loadMoreNews(grid, loader, categoriaId, orden);
        });
    }

    async function loadMoreNews(grid, loader, categoriaId, orden) {
        if (STATE.loadingMore || !STATE.hasMoreNews) return;

        STATE.loadingMore = true;
        STATE.currentPage++;

        // Mostrar skeletons
        if (loader) loader.style.display = 'flex';

        const data = await apiPost('get_more_news', {
            pagina:       STATE.currentPage,
            categoria_id: categoriaId,
            orden,
        });

        STATE.loadingMore = false;
        if (loader) loader.style.display = 'none';

        if (!data.success) {
            showToast('Error al cargar más noticias.', 'error');
            STATE.currentPage--;
            return;
        }

        if (data.html) {
            const fragment = document.createElement('div');
            fragment.innerHTML = data.html;

            // Animar entrada de nuevos cards
            Array.from(fragment.children).forEach((card, i) => {
                card.style.animationDelay = `${i * 60}ms`;
                grid.appendChild(card);
            });

            // Re-inicializar lazy loading para nuevas imágenes
            initLazyImages();
        }

        STATE.hasMoreNews = data.hay_mas;

        if (!STATE.hasMoreNews) {
            const loadBtn = document.getElementById('loadMoreBtn');
            if (loadBtn) {
                loadBtn.textContent = 'No hay más noticias';
                loadBtn.disabled    = true;
                loadBtn.style.opacity = '.5';
            }
        }
    }

    // ============================================================
    // 06. COMENTARIOS
    // ============================================================

    function initComments() {
        const form = document.getElementById('commentForm');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            await submitComment(form);
        });

        // Delegación de eventos para botones dinámicos
        document.addEventListener('click', (e) => {
            // Reply
            if (e.target.closest('.comment-reply-btn')) {
                const btn = e.target.closest('.comment-reply-btn');
                showReplyForm(btn);
            }
            // Eliminar
            if (e.target.closest('.comment-delete-btn')) {
                const btn = e.target.closest('.comment-delete-btn');
                deleteComment(btn);
            }
        });

        // Contador de caracteres
        const textarea = form.querySelector('textarea');
        const counter  = form.querySelector('.char-counter');
        if (textarea && counter) {
            textarea.addEventListener('input', () => {
                const len = textarea.value.length;
                counter.textContent = `${len}/2000`;
                counter.style.color = len > 1800 ? 'var(--danger)' : 'var(--text-muted)';
            });
        }
    }

    async function submitComment(form) {
        if (!requireLogin('Debes iniciar sesión para comentar.')) return;

        const textarea  = form.querySelector('textarea[name="comentario"]');
        const noticiaId = form.querySelector('input[name="noticia_id"]')?.value;
        const padreId   = form.querySelector('input[name="padre_id"]')?.value ?? 0;
        const submitBtn = form.querySelector('[type="submit"]');

        const comentario = textarea?.value.trim();
        if (!comentario || comentario.length < 3) {
            showToast('El comentario debe tener al menos 3 caracteres.', 'warning');
            return;
        }

        submitBtn.disabled = true;
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Enviando...';

        const data = await apiPost('add_comment', {
            noticia_id:  noticiaId,
            comentario,
            padre_id:    padreId || 0,
        });

        submitBtn.disabled   = false;
        submitBtn.innerHTML  = originalText;

        if (data.success) {
            textarea.value = '';
            showToast('¡Comentario publicado!', 'success');

            // Actualizar contador
            const counter = document.getElementById('commentCount');
            if (counter && data.total) {
                counter.textContent = data.total;
            }

            // Insertar nuevo comentario en el DOM
            if (data.comment_html) {
                const list = document.getElementById('commentsList');
                if (list) {
                    const temp = document.createElement('div');
                    temp.innerHTML = data.comment_html;
                    const newComment = temp.firstElementChild;
                    newComment.style.animation = 'slideUp 0.4s ease';

                    if (padreId && padreId !== '0') {
                        // Es una respuesta
                        const parentComment = document.getElementById(`comment-${padreId}`);
                        let repliesContainer = parentComment?.querySelector('.comment-replies');
                        if (!repliesContainer && parentComment) {
                            repliesContainer = document.createElement('div');
                            repliesContainer.className = 'comment-replies';
                            parentComment.querySelector('.comment-body').appendChild(repliesContainer);
                        }
                        repliesContainer?.appendChild(newComment);
                        hideReplyForm(padreId);
                    } else {
                        list.prepend(newComment);
                    }
                }
            }
        } else {
            showToast(data.message || 'Error al publicar el comentario.', 'error');
        }
    }

    function showReplyForm(btn) {
        const commentId = btn.dataset.id;
        const autorNombre = btn.dataset.nombre ?? '';
        const wrap = document.getElementById(`reply-form-${commentId}`);
        if (!wrap) return;

        // Ocultar otros formularios de respuesta abiertos
        document.querySelectorAll('.reply-form-wrap').forEach(w => {
            if (w.id !== `reply-form-${commentId}`) {
                w.setAttribute('hidden', '');
                w.innerHTML = '';
            }
        });

        if (!wrap.hidden && wrap.innerHTML) {
            hideReplyForm(commentId);
            return;
        }

        const noticiaId = document.getElementById('commentForm')
            ?.querySelector('input[name="noticia_id"]')?.value;

        wrap.innerHTML = `
            <form class="reply-form" onsubmit="return false">
                <input type="hidden" name="noticia_id" value="${escapeHtml(noticiaId)}">
                <input type="hidden" name="padre_id" value="${escapeHtml(commentId)}">
                <div class="comment-form-inner">
                    <textarea
                        name="comentario"
                        class="comment-textarea"
                        placeholder="Respondiendo a ${escapeHtml(autorNombre)}..."
                        rows="3"
                        maxlength="2000"
                        autofocus></textarea>
                </div>
                <div class="comment-submit-row">
                    <button type="button" class="btn-cancel-reply"
                            onclick="PDApp.hideReplyForm('${escapeHtml(commentId)}')">
                        Cancelar
                    </button>
                    <button type="submit" class="comment-submit-btn"
                            onclick="PDApp.submitReply(this)">
                        <i class="bi bi-send-fill"></i> Responder
                    </button>
                </div>
            </form>
        `;
        wrap.removeAttribute('hidden');
        wrap.querySelector('textarea')?.focus();
    }

    function hideReplyForm(commentId) {
        const wrap = document.getElementById(`reply-form-${commentId}`);
        if (wrap) {
            wrap.setAttribute('hidden', '');
            wrap.innerHTML = '';
        }
    }

    function submitReply(btn) {
        const form = btn.closest('form');
        if (form) submitComment(form);
    }

    async function deleteComment(btn) {
        const commentId = btn.dataset.id;
        const result    = await showConfirm({
            title:       '¿Eliminar comentario?',
            text:        'Esta acción no se puede deshacer.',
            confirmText: 'Sí, eliminar',
            type:        'warning',
        });

        if (!result.isConfirmed) return;

        const data = await apiPost('delete_comment', { comentario_id: commentId });

        if (data.success) {
            const el = document.getElementById(`comment-${commentId}`);
            if (el) {
                el.style.transition = 'all 0.3s ease';
                el.style.opacity    = '0';
                el.style.transform  = 'translateX(-20px)';
                setTimeout(() => el.remove(), 300);
            }
            showToast('Comentario eliminado.', 'success');
        } else {
            showToast(data.message || 'Error al eliminar.', 'error');
        }
    }

    async function voteComment(btn) {
        if (!requireLogin()) return;

        const commentId = btn.dataset.comentarioId;
        const tipo      = btn.dataset.tipo;

        const data = await apiPost('vote_comment', { comentario_id: commentId, tipo });

        if (data.success) {
            const comment = document.getElementById(`comment-${commentId}`);
            if (comment) {
                const likeBtn    = comment.querySelector('[data-tipo="like"]');
                const dislikeBtn = comment.querySelector('[data-tipo="dislike"]');

                if (likeBtn && data.likes !== undefined) {
                    likeBtn.querySelector('.like-count').textContent = data.likes;
                    likeBtn.classList.toggle('voted-like', data.accion === 'added' && tipo === 'like');
                }
                if (dislikeBtn && data.dislikes !== undefined) {
                    dislikeBtn.querySelector('.dislike-count').textContent = data.dislikes;
                    dislikeBtn.classList.toggle('voted-dislike', data.accion === 'added' && tipo === 'dislike');
                }
            }
        } else {
            showToast(data.message || 'Error al votar.', 'warning');
        }
    }

    // ============================================================
    // 07. REACCIONES
    // ============================================================

    function initReactions() {
        const container = document.getElementById('reactionsBar');
        if (!container) return;

        container.addEventListener('click', async (e) => {
            const btn = e.target.closest('.reaction-btn');
            if (!btn) return;

            const noticiaId = btn.dataset.noticiaId;
            const tipo      = btn.dataset.tipo;

            // Animación de click
            btn.style.transform = 'scale(1.3)';
            setTimeout(() => btn.style.transform = '', 200);

            const data = await apiPost('react_noticia', { noticia_id: noticiaId, tipo });

            if (data.success) {
                updateReactionUI(container, data.totales, data.mi_reaccion ?? (data.accion === 'removed' ? null : tipo));
                showToast(
                    data.accion === 'removed' ? 'Reacción eliminada.' : '¡Reacción registrada!',
                    'success',
                    1500
                );
            } else {
                showToast(data.message || 'Error al reaccionar.', 'warning');
            }
        });
    }

    function updateReactionUI(container, totales, miReaccion) {
        container.querySelectorAll('.reaction-btn').forEach(btn => {
            const tipo  = btn.dataset.tipo;
            const count = btn.querySelector('.reaction-count');
            const total = totales[tipo] ?? 0;

            if (count) count.textContent = total > 0 ? formatNumber(total) : '';
            btn.classList.toggle('active', tipo === miReaccion);
            btn.setAttribute('aria-pressed', tipo === miReaccion ? 'true' : 'false');
        });

        // Total general
        const totalEl = document.getElementById('reactionTotal');
        if (totalEl) {
            const sum = Object.values(totales).reduce((a, b) => a + b, 0);
            totalEl.textContent = formatNumber(sum);
        }
    }

    // ============================================================
    // 08. ENCUESTAS
    // ============================================================

    function initPoll() {
        const pollWidget = document.getElementById('pollWidget');
        if (!pollWidget) return;

        const voteBtn = pollWidget.querySelector('.poll-vote-btn');
        voteBtn?.addEventListener('click', async () => {
            const encuestaId = pollWidget.dataset.encuestaId;
            const selected   = pollWidget.querySelector('input[name="poll_option"]:checked');

            if (!selected) {
                showToast('Selecciona una opción antes de votar.', 'warning');
                return;
            }

            voteBtn.disabled     = true;
            voteBtn.textContent  = 'Votando...';

            const data = await apiPost('vote_poll', {
                encuesta_id: encuestaId,
                opcion_id:   selected.value,
            });

            if (data.success) {
                renderPollResults(pollWidget, data.opciones, data.total_votos);
                showToast('¡Voto registrado!', 'success');
            } else {
                showToast(data.message || 'Error al votar.', 'warning');
                voteBtn.disabled    = false;
                voteBtn.textContent = 'Votar';
            }
        });
    }

    function renderPollResults(widget, opciones, totalVotos) {
        const optionsList = widget.querySelector('.poll-options');
        if (!optionsList) return;

        optionsList.innerHTML = opciones.map(op => {
            const pct = totalVotos > 0 ? Math.round((op.votos / totalVotos) * 100) : 0;
            return `
                <div class="poll-option-result">
                    <div class="poll-option-label">
                        <div class="poll-bar" style="width:${pct}%"></div>
                        <span class="poll-option-text">${escapeHtml(op.opcion)}</span>
                        <span class="poll-pct">${pct}%</span>
                    </div>
                </div>
            `;
        }).join('');

        const voteBtn    = widget.querySelector('.poll-vote-btn');
        const footer     = widget.querySelector('.poll-footer');
        if (voteBtn) voteBtn.remove();
        if (footer)  footer.textContent = `${formatNumber(totalVotos)} votos en total`;
    }

    // ============================================================
    // 09. FAVORITOS
    // ============================================================

    function initFavorites() {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action="toggle-favorite"]');
            if (!btn) return;

            if (!requireLogin('Inicia sesión para guardar noticias.')) return;

            const noticiaId = btn.dataset.noticiaId;
            const icon      = btn.querySelector('i');

            // Animación
            btn.style.transform = 'scale(0.85)';
            setTimeout(() => btn.style.transform = '', 200);

            const data = await apiPost('toggle_favorite', { noticia_id: noticiaId });

            if (data.success) {
                const isSaved = data.action === 'added';
                if (icon) {
                    icon.className = isSaved ? 'bi bi-bookmark-fill' : 'bi bi-bookmark';
                    icon.style.color = isSaved ? 'var(--primary)' : '';
                }
                btn.title = isSaved ? 'Quitar de guardados' : 'Guardar noticia';
                showToast(data.message, 'success', 2000);
            } else {
                showToast(data.message || 'Error al guardar.', 'error');
            }
        });
    }

    // ============================================================
    // 10. COMPARTIR
    // ============================================================

    function initShare() {
        const shareSection = document.getElementById('shareSection');
        if (!shareSection) return;

        shareSection.addEventListener('click', async (e) => {
            const btn = e.target.closest('.share-btn');
            if (!btn) return;

            const noticiaId = btn.dataset.noticiaId;
            const red       = btn.dataset.red;
            const shareUrl  = btn.dataset.url;
            const shareTitle= btn.dataset.title ?? document.title;

            // Registrar compartido
            apiPost('track_share', { noticia_id: noticiaId, red });

            if (red === 'nativo') {
                await window.nativeShare?.(shareTitle, shareUrl);
                return;
            }

            if (red === 'copia') {
                try {
                    await navigator.clipboard.writeText(window.location.href);
                    showToast('¡Enlace copiado al portapapeles!', 'success');
                } catch {
                    showToast('No se pudo copiar el enlace.', 'error');
                }
                return;
            }

            // Abrir ventana de compartir
            window.open(shareUrl, '_blank', 'width=600,height=450,noopener,noreferrer');
        });
    }

    // ============================================================
    // 11. TTS (Text-To-Speech)
    // ============================================================

    function initTTS() {
        const playBtn    = document.getElementById('ttsPlayBtn');
        const stopBtn    = document.getElementById('ttsStopBtn');
        const speedSelect= document.getElementById('ttsSpeed');

        if (!playBtn || !window.speechSynthesis) {
            document.querySelectorAll('.tts-player').forEach(p => p.style.display = 'none');
            return;
        }

        window.TTSPlayer.init('article-content');

        playBtn.addEventListener('click', () => {
            if (window.TTSPlayer.playing) {
                window.TTSPlayer.pause();
            } else {
                window.TTSPlayer.play();
            }
        });

        stopBtn?.addEventListener('click', () => window.TTSPlayer.stop());

        speedSelect?.addEventListener('change', () => {
            window.TTSPlayer.setSpeed(speedSelect.value);
        });
    }

    // ============================================================
    // 12. LIVE BLOG
    // ============================================================

    function initLiveBlog() {
        const container = document.getElementById('liveUpdatesContainer');
        if (!container) return;

        const blogId = container.dataset.blogId;
        if (!blogId) return;

        // Obtener el último ID actual
        const lastUpdate = container.querySelector('.live-update:first-child');
        STATE.lastLiveUpdateId = parseInt(lastUpdate?.dataset.id ?? '0');

        // Polling cada 15 segundos
        STATE.livePoolInterval = setInterval(() => {
            pollLiveBlog(blogId, container);
        }, 15_000);

        // Parar polling si la pestaña no está visible
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(STATE.livePoolInterval);
            } else {
                STATE.livePoolInterval = setInterval(() => {
                    pollLiveBlog(blogId, container);
                }, 15_000);
            }
        });

        // Botón publicar (solo admins)
        const publishForm = document.getElementById('livePublishForm');
        publishForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await publishLiveUpdate(publishForm, blogId);
        });
    }

    async function pollLiveBlog(blogId, container) {
        const data = await apiPost('get_live_updates', {
            blog_id:       blogId,
            despues_de_id: STATE.lastLiveUpdateId,
        });

        if (!data.success) return;

        // Verificar si el blog terminó
        if (data.blog_estado === 'finalizado') {
            clearInterval(STATE.livePoolInterval);
            const statusEl = document.getElementById('liveBlogStatus');
            if (statusEl) {
                statusEl.textContent = 'Cobertura finalizada';
                statusEl.className   = 'live-badge live-badge--ended';
            }
        }

        if (data.updates?.length > 0) {
            // Actualizar contador
            STATE.lastLiveUpdateId = Math.max(...data.updates.map(u => u.id));

            // Mostrar notificación de nuevas actualizaciones
            showToast(`${data.updates.length} nueva${data.updates.length > 1 ? 's' : ''} actualización${data.updates.length > 1 ? 'es' : ''}.`, 'info', 3000);

            // Insertar nuevos updates al inicio
            data.updates.reverse().forEach(update => {
                const html = renderLiveUpdate(update);
                const temp = document.createElement('div');
                temp.innerHTML = html;
                const el = temp.firstElementChild;
                el.style.animation = 'slideInLeft 0.4s ease';
                container.prepend(el);
            });

            // Actualizar total
            const totalEl = document.getElementById('liveTotalUpdates');
            if (totalEl) totalEl.textContent = data.total_updates;
        }
    }
    
    // Convierte URL de YouTube/Vimeo a URL embed válida para iframe
    // YouTube rechaza https://youtube.com/watch?v=ID en iframes
    function getLiveVideoEmbedUrl(url) {
        if (!url) return '';
        // YouTube: watch URL
        const ytWatch = url.match(
            /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_\-]{11})/
        );
        if (ytWatch) {
            return 'https://www.youtube.com/embed/' + ytWatch[1] + '?rel=0&modestbranding=1';
        }
        // YouTube Shorts
        const ytShorts = url.match(/youtube\.com\/shorts\/([a-zA-Z0-9_\-]{11})/);
        if (ytShorts) {
            return 'https://www.youtube.com/embed/' + ytShorts[1] + '?rel=0&modestbranding=1';
        }
        // Vimeo
        const vimeo = url.match(/vimeo\.com\/(\d+)/);
        if (vimeo) {
            return 'https://player.vimeo.com/video/' + vimeo[1];
        }
        // URL ya es embed o MP4 directo → devolver tal cual
        return url;
    }

    function renderLiveUpdate(update) {
        const typeClasses = {
            breaking: 'live-update--breaking',
            alerta:   'live-update--alerta',
            destacado: update.es_destacado ? 'live-update--destacado' : '',
        };

        const typeBadges = {
            breaking: '<span class="live-update__type-badge type-breaking"><i class="bi bi-broadcast-pin"></i> BREAKING</span>',
            alerta:   '<span class="live-update__type-badge type-alerta"><i class="bi bi-exclamation-triangle"></i> ALERTA</span>',
        };

        return `
            <div class="live-update ${typeClasses[update.tipo] ?? ''} ${update.es_destacado ? 'live-update--destacado' : ''}"
                 id="live-update-${update.id}" data-id="${update.id}">
                <div class="live-update__dot"></div>
                <div class="live-update__body">
                    <div class="live-update__header">
                        <div class="live-update__author">
                            <img src="${escapeHtml(update.autor.avatar)}"
                                 alt="${escapeHtml(update.autor.nombre)}"
                                 width="28" height="28">
                            ${escapeHtml(update.autor.nombre)}
                        </div>
                        ${typeBadges[update.tipo] ?? ''}
                        <time class="live-update__time">${escapeHtml(update.fecha_human)}</time>
                    </div>
                    <div class="live-update__content">${update.contenido}</div>
                    ${update.imagen ? `<img src="${escapeHtml(update.imagen)}" class="live-update__img" loading="lazy">` : ''}
                    ${update.video_url ? `
                        <div class="live-update__video"
                             style="position:relative;padding-bottom:56.25%;height:0;
                                    border-radius:var(--border-radius-lg);
                                    overflow:hidden;margin-top:12px;background:#000">
                            <iframe src="${escapeHtml(getLiveVideoEmbedUrl(update.video_url))}"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write;
                                           encrypted-media; gyroscope; picture-in-picture;
                                           web-share"
                                    allowfullscreen
                                    loading="lazy"
                                    style="position:absolute;top:0;left:0;
                                           width:100%;height:100%;border:none">
                            </iframe>
                        </div>` : ''}
                </div>
            </div>
        `;
    }

    async function publishLiveUpdate(form, blogId) {
        const contenido    = form.querySelector('[name="contenido"]')?.value.trim();
        const tipo         = form.querySelector('[name="tipo"]')?.value ?? 'texto';
        const esDestacado  = form.querySelector('[name="es_destacado"]')?.checked ?? false;
        const submitBtn    = form.querySelector('[type="submit"]');

        if (!contenido) {
            showToast('El contenido es requerido.', 'warning');
            return;
        }

        submitBtn.disabled  = true;
        const orig = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';

        const data = await apiPost('post_live_update', {
            blog_id:     blogId,
            contenido,
            tipo,
            es_destacado: esDestacado,
        });

        submitBtn.disabled  = false;
        submitBtn.innerHTML = orig;

        if (data.success) {
            form.reset();
            showToast('¡Actualización publicada!', 'success');
        } else {
            showToast(data.message || 'Error al publicar.', 'error');
        }
    }

    // ============================================================
    // 13. VIDEOS
    // ============================================================

    function initVideos() {
        const player    = document.getElementById('mainVideoPlayer');
        const playlist  = document.getElementById('videoPlaylist');

        if (!playlist) return;

        playlist.addEventListener('click', (e) => {
            const item = e.target.closest('.video-playlist-item');
            if (!item) return;

            // Marcar como activo
            playlist.querySelectorAll('.video-playlist-item')
                .forEach(i => i.classList.remove('active'));
            item.classList.add('active');

            // Actualizar player
            const embedUrl = item.dataset.embedUrl;
            const tipo     = item.dataset.tipo;

            if (player && embedUrl) {
                if (tipo === 'mp4') {
                    player.innerHTML = `
                        <video src="${escapeHtml(embedUrl)}"
                               controls autoplay
                               style="width:100%;height:100%">
                        </video>`;
                } else {
                    player.innerHTML = `
                        <iframe src="${escapeHtml(embedUrl)}?autoplay=1"
                                frameborder="0"
                                allow="autoplay;encrypted-media;fullscreen"
                                allowfullscreen
                                style="width:100%;height:100%">
                        </iframe>`;
                }
            }

            // Actualizar título/info
            const titleEl = document.getElementById('currentVideoTitle');
            if (titleEl) titleEl.textContent = item.dataset.titulo ?? '';
        });
    }

    // ============================================================
    // 14. BÚSQUEDA AVANZADA
    // ============================================================

    function initAdvancedSearch() {
        const form = document.getElementById('searchForm');
        if (!form) return;

        // Auto-submit en cambio de filtros
        form.querySelectorAll('select[data-auto-submit], input[data-auto-submit]')
            .forEach(el => {
                el.addEventListener('change', () => form.submit());
            });

        // Sugerencias en tiempo real
        const input   = form.querySelector('input[name="q"]');
        const suggest = document.getElementById('searchSuggestions');

        if (input && suggest) {
            input.addEventListener('input', debounce(async () => {
                const q = input.value.trim();
                if (q.length < 3) {
                    suggest.setAttribute('hidden', '');
                    return;
                }

                const data = await apiPost('search_suggest', { q });
                if (data.results?.length > 0) {
                    suggest.innerHTML = data.results.map(r => `
                        <a href="${window.APP.url}/noticia.php?slug=${escapeHtml(r.slug)}"
                           class="suggestion-item">
                            <img src="${escapeHtml(r.imagen)}"
                                 alt="" width="40" height="40" loading="lazy">
                            <div>
                                <span class="suggestion-title">${escapeHtml(r.titulo)}</span>
                                <span class="suggestion-cat"
                                      style="color:${escapeHtml(r.cat_color)}">
                                    ${escapeHtml(r.cat_nombre)}
                                </span>
                            </div>
                        </a>
                    `).join('');
                    suggest.removeAttribute('hidden');
                } else {
                    suggest.setAttribute('hidden', '');
                }
            }, 350));

            input.addEventListener('blur', () => {
                setTimeout(() => suggest.setAttribute('hidden', ''), 200);
            });
        }
    }

    // ============================================================
    // 15. LAZY LOADING DE IMÁGENES
    // ============================================================

    function initLazyImages() {
        if (!('IntersectionObserver' in window)) {
            document.querySelectorAll('img[data-src]').forEach(img => {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
            });
            return;
        }

        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        img.classList.add('loaded');
                    }
                    obs.unobserve(img);
                }
            });
        }, { rootMargin: '200px 0px' });

        document.querySelectorAll('img[data-src]').forEach(img => observer.observe(img));
    }

    // ============================================================
    // 16. ANIMACIONES AL SCROLL
    // ============================================================

    function initAnimations() {
        if (!('IntersectionObserver' in window)) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    entry.target.style.opacity   = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        // Preparar elementos
        document.querySelectorAll('.animate-on-scroll').forEach(el => {
            el.style.opacity   = '0';
            el.style.transform = 'translateY(24px)';
            el.style.transition= 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(el);
        });

        // News cards con stagger
        document.querySelectorAll('.news-card').forEach((card, i) => {
            card.style.opacity        = '0';
            card.style.transform      = 'translateY(20px)';
            card.style.transition     = `opacity 0.4s ease ${i * 50}ms, transform 0.4s ease ${i * 50}ms`;
            observer.observe(card);
        });
    }

    // ============================================================
    // 17. ADMIN TABLE
    // ============================================================

    function initAdminTable() {
        // Toggle status switches
        document.querySelectorAll('.admin-toggle').forEach(toggle => {
            toggle.addEventListener('change', async () => {
                const tabla  = toggle.dataset.tabla;
                const id     = toggle.dataset.id;
                const campo  = toggle.dataset.campo  ?? 'activo';
                const valor  = toggle.checked ? 1 : 0;

                const data = await apiPost('admin_toggle_status', { tabla, id, campo, valor });

                if (data.success) {
                    showToast('Estado actualizado.', 'success', 1500);
                } else {
                    toggle.checked = !toggle.checked;
                    showToast('Error al actualizar estado.', 'error');
                }
            });
        });

        // Búsqueda en tabla
        const tableSearch = document.getElementById('tableSearch');
        const tableRows   = document.querySelectorAll('[data-searchable]');

        tableSearch?.addEventListener('input', debounce(() => {
            const q = tableSearch.value.trim().toLowerCase();
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        }, 200));

        // Seleccionar todos
        const selectAll  = document.getElementById('selectAllRows');
        const checkboxes = document.querySelectorAll('.row-checkbox');

        selectAll?.addEventListener('change', () => {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkActions();
        });

        checkboxes.forEach(cb => cb.addEventListener('change', updateBulkActions));
    }

    function updateBulkActions() {
        const selected  = document.querySelectorAll('.row-checkbox:checked').length;
        const bulkBar   = document.getElementById('bulkActionsBar');
        const bulkCount = document.getElementById('bulkCount');

        if (bulkBar) {
            bulkBar.style.display = selected > 0 ? 'flex' : 'none';
        }
        if (bulkCount) {
            bulkCount.textContent = selected;
        }
    }

    // ============================================================
    // 18. CHARTS (Chart.js)
    // ============================================================

    function createLineChart(canvasId, labels, datasets, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) return null;

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

        return new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive:           true,
                maintainAspectRatio:  false,
                interaction:          { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        labels: { color: isDark ? '#b0b0c0' : '#555577' },
                    },
                    tooltip: {
                        backgroundColor: isDark ? '#1a1a2e' : '#fff',
                        titleColor:      isDark ? '#e8e8f0' : '#1a1a2e',
                        bodyColor:       isDark ? '#a0a0c0' : '#4a4a6a',
                        borderColor:     isDark ? '#2a2a45' : '#dee2e6',
                        borderWidth:     1,
                    },
                },
                scales: {
                    x: {
                        grid:  { color: isDark ? '#2a2a45' : '#e2e8f0' },
                        ticks: { color: isDark ? '#606080' : '#8888aa' },
                    },
                    y: {
                        grid:  { color: isDark ? '#2a2a45' : '#e2e8f0' },
                        ticks: { color: isDark ? '#606080' : '#8888aa' },
                    },
                },
                ...options,
            },
        });
    }

    function createDonutChart(canvasId, labels, data, colors = []) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) return null;

        const isDark   = document.documentElement.getAttribute('data-theme') === 'dark';
        const defaults = ['#e63946','#457b9d','#1d3557','#22c55e','#f59e0b','#8b5cf6','#ec4899'];

        return new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: colors.length ? colors : defaults,
                    borderWidth:     3,
                    borderColor:     isDark ? '#131320' : '#ffffff',
                    hoverOffset:     8,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                cutout:              '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color:         isDark ? '#b0b0c0' : '#555577',
                            padding:       16,
                            usePointStyle: true,
                        },
                    },
                },
            },
        });
    }

    function createBarChart(canvasId, labels, datasets, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) return null;

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

        return new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: isDark ? '#b0b0c0' : '#555577' },
                    },
                },
                scales: {
                    x: {
                        grid:  { display: false },
                        ticks: { color: isDark ? '#606080' : '#8888aa' },
                    },
                    y: {
                        grid:  { color: isDark ? '#2a2a45' : '#e2e8f0' },
                        ticks: { color: isDark ? '#606080' : '#8888aa' },
                    },
                },
                ...options,
            },
        });
    }

    function initAdminCharts() {
        // Los charts específicos se inicializan desde cada página admin
        // usando las funciones expuestas en la API pública
        document.dispatchEvent(new CustomEvent('pdChartsReady'));
    }

    // ============================================================
    // 19. NOTIFICACIONES PUSH UI
    // ============================================================

    function initNotificationsUI() {
        const bell   = document.getElementById('notifBell');
        const panel  = document.getElementById('notifPanel');

        bell?.addEventListener('click', async (e) => {
            e.stopPropagation();

            if (panel?.classList.toggle('open')) {
                await loadNotifications(panel);
            }
        });

        document.addEventListener('click', () => {
            panel?.classList.remove('open');
        });

        // Marcar todas como leídas
        document.getElementById('markAllReadBtn')
            ?.addEventListener('click', async () => {
                await apiPost('mark_notifications_read', {});
                document.querySelectorAll('.notif-item--unread')
                    .forEach(el => el.classList.remove('notif-item--unread'));
                document.querySelectorAll('.notif-badge, .nav-notif-count, .bottom-nav__badge')
                    .forEach(el => el.style.display = 'none');
                showToast('Notificaciones marcadas como leídas.', 'success', 2000);
            });
    }

    async function loadNotifications(panel) {
        if (!CONFIG.userId) return;

        const body = panel.querySelector('.notif-panel__body');
        if (!body) return;

        body.innerHTML = '<div class="notif-loading"><i class="bi bi-arrow-repeat spin"></i></div>';

        const data = await apiPost('get_notifications', {});

        if (!data.success || !data.notifications?.length) {
            body.innerHTML = '<p class="notif-empty">No hay notificaciones nuevas.</p>';
            return;
        }

        body.innerHTML = data.notifications.map(n => `
            <a href="${escapeHtml(n.url ?? '#')}"
               class="notif-item ${!n.leida ? 'notif-item--unread' : ''}"
               data-id="${n.id}">
                <div class="notif-icon">
                    <i class="bi bi-bell-fill"></i>
                </div>
                <div class="notif-content">
                    <p class="notif-msg">${escapeHtml(n.mensaje)}</p>
                    <time class="notif-time">${timeAgo(n.fecha)}</time>
                </div>
                ${!n.leida ? '<span class="notif-dot"></span>' : ''}
            </a>
        `).join('');
    }

    // ============================================================
    // 20. MINI PREVIEW AL HOVER
    // ============================================================

    function initMiniPreviews() {
        // Solo en desktop
        if (window.innerWidth < 992) return;

        let previewTimeout;

        document.addEventListener('mouseover', (e) => {
            const card = e.target.closest('.news-card[data-preview]');
            if (!card) return;

            previewTimeout = setTimeout(() => {
                showMiniPreview(card);
            }, 600);
        });

        document.addEventListener('mouseout', (e) => {
            clearTimeout(previewTimeout);
            const card = e.target.closest('.news-card');
            if (!card) return;
            card.querySelector('.news-preview-tooltip')?.remove();
        });
    }

    function showMiniPreview(card) {
        const existing = card.querySelector('.news-preview-tooltip');
        if (existing) return;

        const titulo   = card.dataset.titulo   ?? '';
        const resumen  = card.dataset.resumen  ?? '';
        const vistas   = card.dataset.vistas   ?? '0';
        const tiempo   = card.dataset.tiempo   ?? '1';

        const tooltip = document.createElement('div');
        tooltip.className = 'news-preview-tooltip';
        tooltip.innerHTML = `
            <p style="font-size:.8rem;font-weight:600;margin-bottom:6px;color:var(--text-primary)">
                ${escapeHtml(titulo)}
            </p>
            <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:8px;line-height:1.4">
                ${escapeHtml(resumen)}
            </p>
            <div style="display:flex;gap:12px;font-size:.7rem;color:var(--text-muted)">
                <span><i class="bi bi-eye"></i> ${formatNumber(parseInt(vistas))}</span>
                <span><i class="bi bi-clock"></i> ${tiempo} min</span>
            </div>
        `;
        card.appendChild(tooltip);

        // Posicionar
        setTimeout(() => {
            tooltip.style.opacity   = '1';
            tooltip.style.transform = 'translateY(0)';
        }, 10);
    }

    // ============================================================
    // 21. MODO LECTURA
    // ============================================================

    function initReadingMode() {
        const btn = document.getElementById('readingModeBtn');
        if (!btn) return;

        let active = localStorage.getItem('pd_reading_mode') === '1';
        applyReadingMode(active);

        btn.addEventListener('click', () => {
            active = !active;
            applyReadingMode(active);
            localStorage.setItem('pd_reading_mode', active ? '1' : '0');
            showToast(
                active ? 'Modo lectura activado.' : 'Modo lectura desactivado.',
                'info',
                2000
            );
        });
    }

    function applyReadingMode(active) {
        document.body.classList.toggle('reading-mode', active);
        const btn = document.getElementById('readingModeBtn');
        if (btn) {
            btn.innerHTML = active
                ? '<i class="bi bi-book-fill"></i> Salir de lectura'
                : '<i class="bi bi-book"></i> Modo lectura';
            btn.classList.toggle('active', active);
        }
    }

    // Barra de progreso de lectura
    function initReadingProgress() {
        const progressBar = document.getElementById('readingProgressBar');
        if (!progressBar) return;

        window.addEventListener('scroll', throttle(() => {
            const article = document.getElementById('article-content');
            if (!article) return;

            const rect   = article.getBoundingClientRect();
            const total  = article.offsetHeight;
            const read   = Math.max(0, -rect.top);
            const pct    = Math.min(100, (read / (total - window.innerHeight)) * 100);

            progressBar.style.width = pct + '%';
        }, 50), { passive: true });
    }

    // ============================================================
    // 22. SEGUIR USUARIO / CATEGORÍA
    // ============================================================

    function initFollow() {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action="follow-user"]');
            if (!btn) return;

            if (!requireLogin('Inicia sesión para seguir usuarios.')) return;

            const seguidoId = btn.dataset.userId;
            const data      = await apiPost('toggle_follow', { seguido_id: seguidoId });

            if (data.success) {
                const isFollowing = data.action === 'followed';
                btn.textContent  = isFollowing ? 'Siguiendo' : 'Seguir';
                btn.classList.toggle('btn-following', isFollowing);

                const countEl = document.getElementById('followersCount');
                if (countEl) {
                    const current = parseInt(countEl.textContent.replace(/\D/g, '')) || 0;
                    countEl.textContent = formatNumber(isFollowing ? current + 1 : Math.max(0, current - 1));
                }

                showToast(data.message, 'success', 2000);
            } else {
                showToast(data.message || 'Error.', 'error');
            }
        });

        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action="follow-cat"]');
            if (!btn) return;

            if (!requireLogin('Inicia sesión para seguir categorías.')) return;

            const catId = btn.dataset.catId;
            const data  = await apiPost('toggle_follow_cat', { categoria_id: catId });

            if (data.success) {
                const isFollowing = data.action === 'followed';
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.className = isFollowing
                        ? 'bi bi-bell-fill'
                        : 'bi bi-bell';
                }
                btn.title = isFollowing ? 'Dejar de seguir' : 'Seguir categoría';
                showToast(data.message, 'success', 2000);
            } else {
                showToast(data.message || 'Error.', 'error');
            }
        });
    }

    // ============================================================
    // 23. REPORTAR CONTENIDO
    // ============================================================

    function reportContent(tipo, itemId) {
        if (!requireLogin('Debes iniciar sesión para reportar contenido.')) return;

        const motivos = [
            { value: 'spam',       label: 'Spam o contenido repetitivo' },
            { value: 'odio',       label: 'Discurso de odio' },
            { value: 'falso',      label: 'Información falsa' },
            { value: 'inapropiado',label: 'Contenido inapropiado' },
            { value: 'derechos',   label: 'Violación de derechos de autor' },
            { value: 'otro',       label: 'Otro motivo' },
        ];

        const html = `
            <div class="report-form">
                <p style="margin-bottom:16px;color:var(--text-muted);font-size:.875rem">
                    Ayúdanos a mantener la calidad del contenido reportando lo que consideres inapropiado.
                </p>
                <div class="form-group">
                    <label class="form-label">Motivo del reporte</label>
                    <select id="reportMotivo" class="form-select">
                        ${motivos.map(m => `<option value="${m.value}">${m.label}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Descripción (opcional)</label>
                    <textarea id="reportDesc"
                              class="comment-textarea"
                              rows="3"
                              maxlength="500"
                              placeholder="Describe el problema..."></textarea>
                </div>
            </div>
        `;

        const footer = `
            <button onclick="closeModal()" class="btn-cancel-reply">Cancelar</button>
            <button onclick="PDApp.submitReport('${tipo}', ${itemId})"
                    class="comment-submit-btn">
                <i class="bi bi-flag-fill"></i> Enviar Reporte
            </button>
        `;

        window.openModal?.('Reportar Contenido', html, footer);
    }

    async function submitReport(tipo, itemId) {
        const motivo = document.getElementById('reportMotivo')?.value ?? 'otro';
        const desc   = document.getElementById('reportDesc')?.value   ?? '';

        const data = await apiPost('report_content', {
            tipo,
            item_id:     itemId,
            motivo,
            descripcion: desc,
        });

        window.closeModal?.();

        if (data.success) {
            showToast('Reporte enviado. ¡Gracias!', 'success');
        } else {
            showToast(data.message || 'Error al enviar el reporte.', 'error');
        }
    }

    // ============================================================
    // 24. PWA HELPERS
    // ============================================================

    function initPWA() {
        // Ya inicializado en footer.php
        // Aquí solo manejamos actualizaciones de estado
        window.addEventListener('appinstalled', () => {
            showToast('¡App instalada exitosamente!', 'success', 5000);
        });
    }

    // ============================================================
    // 25. TOOLTIPS
    // ============================================================

    function initTooltips() {
        document.querySelectorAll('[data-tooltip]').forEach(el => {
            el.addEventListener('mouseenter', () => {
                const text    = el.dataset.tooltip;
                const tooltip = document.createElement('div');
                tooltip.className   = 'pd-tooltip';
                tooltip.textContent = text;
                tooltip.style.cssText = `
                    position:absolute;
                    background:rgba(0,0,0,.8);
                    color:#fff;
                    padding:4px 10px;
                    border-radius:6px;
                    font-size:12px;
                    pointer-events:none;
                    white-space:nowrap;
                    z-index:9999;
                    transform:translateY(-110%);
                `;

                el.style.position = 'relative';
                el.appendChild(tooltip);

                setTimeout(() => { tooltip.style.opacity = '1'; }, 10);
            });

            el.addEventListener('mouseleave', () => {
                el.querySelector('.pd-tooltip')?.remove();
            });
        });
    }

    // ============================================================
    // FORMULARIOS GLOBALES
    // ============================================================

    function initGlobalForms() {
        // Mostrar/ocultar password
        document.querySelectorAll('[data-toggle-password]').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.dataset.togglePassword;
                const input    = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type       = isPassword ? 'text' : 'password';
                const icon       = btn.querySelector('i');
                if (icon) {
                    icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                }
            });
        });

        // Inputs numéricos: solo números
        document.querySelectorAll('input[data-numeric]').forEach(input => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/[^0-9]/g, '');
            });
        });

        // Auto-resize textareas
        document.querySelectorAll('textarea[data-auto-resize]').forEach(ta => {
            ta.addEventListener('input', () => {
                ta.style.height = 'auto';
                ta.style.height = ta.scrollHeight + 'px';
            });
        });
    }

    // ============================================================
    // PERFIL
    // ============================================================

    function initProfile() {
        // Tabs de perfil
        const tabs    = document.querySelectorAll('.profile-tab');
        const panels  = document.querySelectorAll('.profile-panel');

        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const target = tab.dataset.tab;

                tabs.forEach(t => t.classList.remove('active'));
                panels.forEach(p => p.setAttribute('hidden', ''));

                tab.classList.add('active');
                document.getElementById(`panel-${target}`)?.removeAttribute('hidden');

                // Actualizar URL sin recargar
                const url = new URL(window.location.href);
                url.searchParams.set('tab', target);
                window.history.replaceState({}, '', url.toString());
            });
        });

        // Avatar upload
        const avatarInput = document.getElementById('avatarInput');
        const avatarPreview = document.getElementById('avatarPreview');

        avatarInput?.addEventListener('change', () => {
            const file = avatarInput.files[0];
            if (!file) return;

            if (file.size > 2 * 1024 * 1024) {
                showToast('La imagen no puede superar 2MB.', 'warning');
                avatarInput.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                if (avatarPreview) avatarPreview.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });

        // Inicializar follow/favoritos
        initFollow();
        initFavorites();
    }

    // ============================================================
    // API PÚBLICA
    // ============================================================

    return {
        // Core
        showToast,
        showConfirm,
        applyDarkMode,
        escapeHtml,
        debounce,
        throttle,
        formatNumber,
        timeAgo,

        // Acciones
        voteComment,
        submitReply,
        showReplyForm,
        hideReplyForm,
        reportContent,
        submitReport,

        // Charts
        createLineChart,
        createDonutChart,
        createBarChart,

        // Admin
        initAdminTable,

        // Init
        init,
    };

})();

// ============================================================
// INICIALIZAR AL DOM READY
// ============================================================

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', PDApp.init);
} else {
    PDApp.init();
}

// ============================================================
// HELPERS GLOBALES (accesibles desde HTML inline)
// ============================================================

/**
 * Confirmar y eliminar un elemento
 */
function confirmDelete(url, msg = '¿Eliminar este registro permanentemente?') {
    PDApp.showConfirm({
        title:       '¿Eliminar?',
        text:        msg,
        confirmText: 'Sí, eliminar',
        type:        'warning',
    }).then(result => {
        if (result.isConfirmed) window.location.href = url;
    });
}

/**
 * Copiar texto al portapapeles
 */
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        PDApp.showToast('✅ Copiado al portapapeles', 'success', 2000);
    } catch {
        PDApp.showToast('No se pudo copiar.', 'error');
    }
}

/**
 * Eliminar rápido vía AJAX (admin)
 */
async function quickDelete(tabla, id, el) {
    const result = await PDApp.showConfirm({
        title:       '¿Eliminar?',
        text:        'Esta acción no se puede deshacer.',
        confirmText: 'Sí, eliminar',
        type:        'warning',
    });

    if (!result.isConfirmed) return;

    const data = await fetch(window.APP.url + '/ajax/handler.php', {
        method:  'POST',
        headers: {
            'Content-Type':     'application/json',
            'X-CSRF-Token':     window.APP.csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            action: 'admin_quick_delete',
            tabla,
            id,
        }),
    }).then(r => r.json());

    if (data.success) {
        const row = el?.closest('tr') ?? el?.closest('[data-row]');
        if (row) {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity    = '0';
            row.style.transform  = 'translateX(-20px)';
            setTimeout(() => row.remove(), 300);
        }
        PDApp.showToast('Eliminado correctamente.', 'success');
    } else {
        PDApp.showToast(data.message || 'Error al eliminar.', 'error');
    }
}

/**
 * Cambiar estado vía toggle (admin inline)
 */
async function toggleStatus(tabla, id, campo, valor, el) {
    const data = await fetch(window.APP.url + '/ajax/handler.php', {
        method:  'POST',
        headers: {
            'Content-Type':     'application/json',
            'X-CSRF-Token':     window.APP.csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            action: 'admin_toggle_status',
            tabla, id, campo, valor,
        }),
    }).then(r => r.json());

    if (data.success) {
        PDApp.showToast('Estado actualizado.', 'success', 1500);
    } else {
        // Revertir toggle visualmente
        if (el) el.checked = !el.checked;
        PDApp.showToast('Error al actualizar.', 'error');
    }
}