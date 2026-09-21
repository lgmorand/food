/* Food — interface mobile-first (vanilla JS, sans dépendance). */
(() => {
    'use strict';

    const app = document.getElementById('app');
    const toastEl = document.getElementById('toast');

    const state = {
        user: null,
        defaultUsername: 'morand',
        units: {},
        categories: {},
        view: 'home',
        params: {},
        data: {},
        menuSize: Number(localStorage.getItem('food.menuSize') || 5),
        loading: false,
        stack: [],
    };

    /* ---------------------------------------------------------------- API */

    // L'application peut être publiée dans un sous-dossier : toutes les URL
    // sont résolues depuis <base href>, jamais depuis la racine du domaine.
    const url = (relative) => {
        const value = String(relative ?? '');
        if (/^[a-z]+:\/\//i.test(value) || value.startsWith('data:')) return value;

        return new URL(value.replace(/^\/+/, ''), document.baseURI).href;
    };

    async function api(path, options = {}) {
        const opts = { credentials: 'same-origin', headers: {}, ...options };
        if (opts.body !== undefined && !(opts.body instanceof FormData)) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }

        // Les lectures sont rejouées : une connexion mobile peut couper ponctuellement.
        const attempts = (opts.method || 'GET').toUpperCase() === 'GET' ? 3 : 1;
        let res = null;
        for (let attempt = 1; attempt <= attempts; attempt += 1) {
            try {
                res = await fetch(url('api' + path), opts);
                break;
            } catch (networkError) {
                if (attempt === attempts) {
                    const offline = new Error('Connexion indisponible. Vérifiez votre réseau.');
                    offline.status = 0;
                    throw offline;
                }
                await new Promise((resolve) => setTimeout(resolve, 150 * attempt));
            }
        }
        if (res.status === 204) return null;

        let payload = null;
        try { payload = await res.json(); } catch (e) { payload = null; }

        if (!res.ok) {
            const error = new Error((payload && payload.message) || 'Une erreur est survenue.');
            error.status = res.status;
            error.errors = (payload && payload.errors) || {};
            throw error;
        }
        if (payload === null) {
            throw new Error('Réponse inattendue du serveur, merci de réessayer.');
        }
        return payload;
    }

    /* -------------------------------------------------------------- Utils */

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    function toast(message, isError = false) {
        toastEl.textContent = message;
        toastEl.className = 'toast show' + (isError ? ' error' : '');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => { toastEl.className = 'toast'; }, 3000);
    }

    function formatWeek(weekStart) {
        if (!weekStart) return '';
        const start = new Date(weekStart + 'T00:00:00');
        const end = new Date(start);
        end.setDate(end.getDate() + 6);
        const fmt = (d) => d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
        return `${fmt(start)} → ${fmt(end)}`;
    }

    function go(view, params = {}) {
        if (state.view !== view && state.view !== 'login' && state.view !== 'setup') {
            state.stack.push({ view: state.view, params: state.params });
            if (state.stack.length > 20) state.stack.shift();
        }
        state.view = view;
        state.params = params;
        render();
        loadView();
        window.scrollTo(0, 0);
    }

    function back() {
        const previous = state.stack.pop();
        state.view = previous ? previous.view : 'home';
        state.params = previous ? previous.params : {};
        render();
        loadView();
    }

    async function withLoader(fn) {
        state.loading = true;
        render();
        try {
            await fn();
        } catch (e) {
            toast(e.message, true);
        } finally {
            state.loading = false;
            render();
        }
    }

    /* ------------------------------------------------------------ Chargement */

    async function loadView() {
        state.loadError = null;
        try {
            if (state.view === 'home' || state.view === 'menu' || state.view === 'validate') {
                state.data.current = await api('/menus/current');
            } else if (state.view === 'recipes') {
                state.data.recipes = (await api('/recipes')).recipes;
            } else if (state.view === 'recipe') {
                state.data.ingredients = (await api('/ingredients')).ingredients;
                state.data.recipe = state.params.id
                    ? (await api('/recipes/' + state.params.id)).recipe
                    : { name: '', photoUrl: null, isActive: true, tags: [], ingredients: [] };
                state.data.draftLines = state.data.recipe.ingredients.map((i) => ({ ...i }));
            } else if (state.view === 'shopping') {
                state.data.shoppingList = state.params.id
                    ? (await api('/shopping-lists/' + state.params.id)).shoppingList
                    : (await api('/shopping-list/current')).shoppingList;
            } else if (state.view === 'history') {
                state.data.history = (await api('/menus/history')).menus;
            } else if (state.view === 'catalog' || state.view === 'settings') {
                state.data.ingredients = (await api('/ingredients')).ingredients;
            }
        } catch (e) {
            if (e.status === 401) { state.user = null; go('login'); return; }
            state.loadError = e.message;
            toast(e.message, true);
        }
        render();
    }

    /* --------------------------------------------------------- Vues : auth */

    function renderAuth() {
        const isSetup = state.view === 'setup';
        const defaultUsername = state.defaultUsername || 'morand';

        app.innerHTML = `
            <div class="auth-wrap">
                <div class="logo">🥕</div>
                <h1 class="center">Food</h1>
                <p class="center muted">Le menu de la semaine et les courses qui vont avec.</p>
                <div class="card">
                    ${isSetup ? `<p class="muted">Première utilisation : choisissez le mot de passe
                        du compte partagé.</p>` : ''}
                    <form id="auth-form">
                        <div class="field">
                            <label for="username">Identifiant</label>
                            <input id="username" name="username" required autocomplete="username"
                                   value="${esc(defaultUsername)}">
                        </div>
                        <div class="field">
                            <label for="password">Mot de passe</label>
                            <input id="password" name="password" type="password" required
                                   autocomplete="${isSetup ? 'new-password' : 'current-password'}">
                        </div>
                        ${isSetup ? `
                            <div class="field">
                                <label for="confirmation">Confirmer le mot de passe</label>
                                <input id="confirmation" name="confirmation" type="password" required
                                       autocomplete="new-password">
                            </div>` : ''}
                        <button class="btn-primary btn-block" type="submit">
                            ${isSetup ? 'Créer le compte' : 'Se connecter'}
                        </button>
                    </form>
                </div>
            </div>`;

        app.querySelector('#auth-form').onsubmit = async (event) => {
            event.preventDefault();
            const body = Object.fromEntries(new FormData(event.target).entries());
            if (isSetup && body.password !== body.confirmation) {
                toast('Les deux mots de passe ne correspondent pas.', true);
                return;
            }
            delete body.confirmation;
            try {
                const res = await api(isSetup ? '/auth/setup' : '/auth/login', { method: 'POST', body });
                state.user = res.user;
                await bootstrapSession();
                go('home');
            } catch (e) {
                toast(e.message, true);
            }
        };
    }

    /* -------------------------------------------------------- Vue : accueil */

    function renderHome() {
        const current = state.data.current || {};
        const draft = current.draft;
        const validated = current.validated;
        const list = current.shoppingList;

        let body = '';

        if (validated) {
            body += `
                <div class="card">
                    <div class="day-label">Menu validé — semaine du ${esc(formatWeek(validated.weekStart))}</div>
                    <div class="menu-grid" style="margin-top:10px">
                        ${validated.items.map((item) => menuCardHtml(item, false)).join('')}
                    </div>
                </div>
                <button class="btn-primary btn-block" id="go-shopping" style="margin-bottom:12px">
                    🛒 Ma liste de courses${list ? ` (${list.totalCount - list.checkedCount} restants)` : ''}
                </button>
                <button class="btn-ghost btn-block" id="new-menu">Refaire un menu pour cette semaine</button>`;
        } else if (draft) {
            body += `
                <div class="card center">
                    <p>Un menu est en cours de préparation pour la semaine du
                       <strong>${esc(formatWeek(draft.weekStart))}</strong></p>
                    <button class="btn-primary btn-block" id="resume-menu">Reprendre la préparation</button>
                </div>`;
        } else {
            body += `
                <div class="card center">
                    <p class="muted">Aucun menu pour la semaine du ${esc(formatWeek(current.weekStart))}</p>
                    <div class="segmented" style="margin-bottom:12px">
                        <button data-size="5" class="${state.menuSize === 5 ? 'active' : ''}">5 recettes</button>
                        <button data-size="6" class="${state.menuSize === 6 ? 'active' : ''}">6 recettes</button>
                    </div>
                    <button class="btn-primary btn-block" id="generate">🎲 Proposer un menu</button>
                </div>`;
        }

        app.innerHTML = topbar('Cette semaine', formatWeek(current.weekStart), true)
            + `<div class="screen">${body}</div>` + tabbar('home');
        bindChrome();

        app.querySelectorAll('.segmented button[data-size]').forEach((btn) => {
            btn.onclick = () => {
                state.menuSize = Number(btn.dataset.size);
                localStorage.setItem('food.menuSize', String(state.menuSize));
                render();
            };
        });

        const generate = app.querySelector('#generate') || app.querySelector('#new-menu');
        if (generate) {
            generate.onclick = () => withLoader(async () => {
                const res = await api('/menus/generate', { method: 'POST', body: { size: state.menuSize } });
                state.data.menu = res.menu;
                state.data.warning = res.warning;
                go('menu', { id: res.menu.id });
            });
        }
        const resume = app.querySelector('#resume-menu');
        if (resume) resume.onclick = () => go('menu', { id: draft.id });

        const shopping = app.querySelector('#go-shopping');
        if (shopping) shopping.onclick = () => go('shopping', list ? { id: list.id } : {});
    }

    /* ----------------------------------------------------------- Vue : menu */

    function menuCardHtml(item, editable) {
        const photo = item.photoUrl
            ? `<div class="thumb" style="background-image:url('${esc(url(item.photoUrl))}')"></div>`
            : '<div class="thumb">🍽️</div>';

        return `
            <div class="menu-card ${item.locked ? 'locked' : ''}" data-position="${item.position}">
                ${photo}
                <div class="grow" style="flex:1;min-width:0">
                    <div class="day-label">Repas ${item.position + 1}</div>
                    <div class="name">${esc(item.recipeName)}</div>
                </div>
                ${editable ? `
                    <div class="actions">
                        <button class="icon-btn" data-action="lock" title="Verrouiller">${item.locked ? '🔒' : '🔓'}</button>
                        <button class="icon-btn" data-action="replace" title="Remplacer">🔄</button>
                        <button class="icon-btn" data-action="remove" title="Retirer">🗑️</button>
                    </div>` : ''}
            </div>`;
    }

    function renderMenu() {
        const current = state.data.current || {};
        const menu = state.data.menu
            || (current.draft && current.draft.id === state.params.id ? current.draft : null)
            || current.draft;

        if (!menu) {
            app.innerHTML = topbar('Menu de la semaine', '', true)
                + `<div class="screen"><div class="empty"><span class="ico">🍲</span>
                     Aucun menu en préparation.
                     <p><button class="btn-primary" id="back-home">Retour à l'accueil</button></p>
                   </div></div>` + tabbar('menu');
            bindChrome();
            const back = app.querySelector('#back-home');
            if (back) back.onclick = () => go('home');
            return;
        }

        app.innerHTML = topbar('Menu proposé', formatWeek(menu.weekStart), true) + `
            <div class="screen">
                ${state.data.warning ? `<div class="notice">${esc(state.data.warning)}</div>` : ''}
                <p class="muted">Remplacez (🔄) ou retirez (🗑️) une recette : une autre est tirée au hasard.
                   Verrouillez (🔒) celles que vous gardez.</p>
                <div class="menu-grid">${menu.items.map((i) => menuCardHtml(i, true)).join('')}</div>
                <div class="row" style="margin-top:16px">
                    <button class="btn-secondary" id="regenerate">🎲 Tout regénérer</button>
                    <button class="btn-primary grow" id="validate" style="flex:1">Valider le menu</button>
                </div>
            </div>` + tabbar('menu');
        bindChrome();

        app.querySelectorAll('.menu-card .icon-btn').forEach((btn) => {
            const position = btn.closest('.menu-card').dataset.position;
            const action = btn.dataset.action;
            btn.onclick = () => withLoader(async () => {
                let res;
                if (action === 'replace') {
                    res = await api(`/menus/${menu.id}/items/${position}/replace`, { method: 'POST' });
                } else if (action === 'remove') {
                    res = await api(`/menus/${menu.id}/items/${position}`, { method: 'DELETE' });
                } else {
                    const locked = !menu.items.find((i) => String(i.position) === position).locked;
                    res = await api(`/menus/${menu.id}/items/${position}/lock`, { method: 'POST', body: { locked } });
                }
                state.data.menu = res.menu;
                state.data.warning = res.warning;
            });
        });

        app.querySelector('#regenerate').onclick = () => withLoader(async () => {
            const res = await api(`/menus/${menu.id}/regenerate`, { method: 'POST' });
            state.data.menu = res.menu;
            state.data.warning = res.warning;
        });

        app.querySelector('#validate').onclick = () => go('validate', { id: menu.id });
    }

    /* ----------------------------------------------------- Vue : validation */

    function renderValidate() {
        const menu = state.data.menu || (state.data.current && state.data.current.draft);
        if (!menu) { go('menu'); return; }

        app.innerHTML = topbar('Validation', formatWeek(menu.weekStart), true) + `
            <div class="screen">
                <div class="card">
                    <h2 style="margin-top:0">Les ${menu.items.length} repas de la semaine</h2>
                    <div class="menu-grid">${menu.items.map((i) => menuCardHtml(i, false)).join('')}</div>
                </div>
                <p class="muted">En validant, la liste de courses est générée automatiquement à partir
                   des ingrédients de ces recettes.</p>
                <div class="row">
                    <button class="btn-ghost" id="back">Retour</button>
                    <button class="btn-primary" id="confirm" style="flex:1">✅ Valider et générer les courses</button>
                </div>
            </div>` + tabbar('menu');
        bindChrome();

        app.querySelector('#back').onclick = () => go('menu', { id: menu.id });
        app.querySelector('#confirm').onclick = () => withLoader(async () => {
            const res = await api(`/menus/${menu.id}/validate`, { method: 'POST' });
            state.data.menu = null;
            toast('Menu validé, liste de courses prête !');
            go('shopping', { id: res.shoppingList.id });
        });
    }

    /* -------------------------------------------------------- Vue : courses */

    function renderShopping() {
        const list = state.data.shoppingList;

        if (!list) {
            app.innerHTML = topbar('Liste de courses', '', true)
                + `<div class="screen"><div class="empty"><span class="ico">🛒</span>
                     Aucune liste : validez d'abord un menu.
                     <p><button class="btn-primary" id="to-menu">Créer un menu</button></p>
                   </div></div>` + tabbar('shopping');
            bindChrome();
            app.querySelector('#to-menu').onclick = () => go('home');
            return;
        }

        const progress = list.totalCount ? Math.round((list.checkedCount / list.totalCount) * 100) : 0;

        app.innerHTML = topbar('Liste de courses', formatWeek(list.weekStart), true) + `
            <div class="screen">
                <div class="card">
                    <div class="row">
                        <strong>${list.checkedCount} / ${list.totalCount}</strong>
                        <span class="spacer"></span>
                        <button class="btn-ghost btn-sm" id="uncheck">Tout décocher</button>
                        <button class="btn-ghost btn-sm" id="share">Partager</button>
                    </div>
                    <div class="progress"><span style="width:${progress}%"></span></div>
                </div>

                ${list.groups.map((group) => `
                    <div class="group-title">${esc(group.label)}</div>
                    ${group.items.map((item) => `
                        <div class="shop-item ${item.checked ? 'checked' : ''}" data-id="${item.id}">
                            <button class="check" aria-label="Cocher ${esc(item.label)}">${item.checked ? '✓' : ''}</button>
                            <div style="flex:1;min-width:0">
                                <div class="label">${esc(item.label)}
                                    ${item.display ? `<span class="qty">— ${esc(item.display)}</span>` : ''}</div>
                                ${item.sourceRecipes.length
                                    ? `<div class="sources">${esc(item.sourceRecipes.join(', '))}</div>`
                                    : '<div class="sources">Ajouté manuellement</div>'}
                            </div>
                            ${item.isManual ? '<button class="trash" aria-label="Supprimer">✕</button>' : ''}
                        </div>`).join('')}
                `).join('')}

                <form class="card" id="add-item" style="margin-top:18px">
                    <label for="manual-label">Ajouter un article</label>
                    <div class="row">
                        <input id="manual-label" placeholder="Sacs poubelle, pain…" required>
                        <button class="btn-secondary" type="submit">+</button>
                    </div>
                    <div class="field" style="margin-top:10px">
                        <label for="manual-category">Rayon</label>
                        <select id="manual-category">
                            ${Object.entries(state.categories)
                                .map(([key, label]) => `<option value="${key}">${esc(label)}</option>`).join('')}
                        </select>
                    </div>
                </form>
            </div>` + tabbar('shopping');
        bindChrome();

        app.querySelectorAll('.shop-item').forEach((row) => {
            const itemId = row.dataset.id;
            row.querySelector('.check').onclick = async () => {
                const checked = !row.classList.contains('checked');
                row.classList.toggle('checked', checked);
                try {
                    const res = await api(`/shopping-lists/${list.id}/items/${itemId}`, {
                        method: 'PATCH', body: { checked },
                    });
                    state.data.shoppingList = res.shoppingList;
                    render();
                } catch (e) { toast(e.message, true); }
            };
            const trash = row.querySelector('.trash');
            if (trash) {
                trash.onclick = () => withLoader(async () => {
                    const res = await api(`/shopping-lists/${list.id}/items/${itemId}`, { method: 'DELETE' });
                    state.data.shoppingList = res.shoppingList;
                });
            }
        });

        app.querySelector('#uncheck').onclick = () => withLoader(async () => {
            const res = await api(`/shopping-lists/${list.id}/uncheck-all`, { method: 'POST' });
            state.data.shoppingList = res.shoppingList;
        });

        app.querySelector('#share').onclick = async () => {
            try {
                const res = await api(`/shopping-lists/${list.id}/export`);
                if (navigator.share) {
                    await navigator.share({ title: 'Liste de courses', text: res.text });
                } else {
                    await navigator.clipboard.writeText(res.text);
                    toast('Liste copiée dans le presse-papiers');
                }
            } catch (e) {
                if (e.name !== 'AbortError') toast(e.message, true);
            }
        };

        app.querySelector('#add-item').onsubmit = (event) => {
            event.preventDefault();
            const label = app.querySelector('#manual-label').value.trim();
            const category = app.querySelector('#manual-category').value;
            if (!label) return;
            withLoader(async () => {
                const res = await api(`/shopping-lists/${list.id}/items`, {
                    method: 'POST', body: { label, category },
                });
                state.data.shoppingList = res.shoppingList;
            });
        };
    }

    /* ------------------------------------------------------- Vue : recettes */

    function renderRecipes() {
        const recipes = state.data.recipes || [];

        app.innerHTML = topbar('Mes recettes', `${recipes.length} recette(s)`, true) + `
            <div class="screen">
                <div class="field">
                    <input id="search" placeholder="🔍 Rechercher une recette" value="${esc(state.params.search || '')}">
                </div>
                <button class="btn-primary btn-block" id="add-recipe" style="margin-bottom:16px">
                    ＋ Ajouter une recette
                </button>
                ${recipes.length === 0
                    ? `<div class="empty"><span class="ico">📖</span>Votre catalogue est vide.<br>
                         Ajoutez vos recettes pour générer des menus.</div>`
                    : `<div class="recipe-list">${recipes.map((recipe) => `
                        <button class="recipe-row" data-id="${recipe.id}">
                            ${recipe.photoUrl
                                ? `<div class="thumb" style="background-image:url('${esc(url(recipe.photoUrl))}')"></div>`
                                : '<div class="thumb">🍽️</div>'}
                            <div style="flex:1;min-width:0">
                                <div class="name">${esc(recipe.name)}</div>
                                <div class="muted">${recipe.ingredientCount} ingrédient(s)</div>
                            </div>
                            <span class="badge off" ${recipe.isActive ? 'hidden' : ''}>en pause</span>
                        </button>`).join('')}</div>`}
            </div>` + tabbar('recipes');
        bindChrome();

        const search = app.querySelector('#search');
        search.oninput = debounce(async () => {
            const value = search.value.trim();
            state.params.search = value;
            try {
                const res = await api('/recipes?search=' + encodeURIComponent(value));
                state.data.recipes = res.recipes;
            } catch (e) {
                toast(e.message, true);
                return;
            }
            render();
            const input = app.querySelector('#search');
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }, 300);

        app.querySelector('#add-recipe').onclick = () => go('recipe', {});
        app.querySelectorAll('.recipe-row').forEach((row) => {
            row.onclick = () => go('recipe', { id: row.dataset.id });
        });
    }

    /* --------------------------------------------------- Vue : fiche recette */

    function renderRecipeForm() {
        const recipe = state.data.recipe;
        if (!recipe) return;
        const lines = state.data.draftLines || [];
        const units = Object.entries(state.units);

        app.innerHTML = topbar(state.params.id ? 'Modifier la recette' : 'Nouvelle recette', '', true) + `
            <div class="screen">
                <div class="card">
                    <div class="field">
                        <label for="name">Nom de la recette *</label>
                        <input id="name" value="${esc(recipe.name)}" maxlength="120" placeholder="Ex. Pâtes bolognaise">
                    </div>

                    <div class="field">
                        <label>Photo (optionnelle)</label>
                        <div class="row">
                            ${recipe.photoUrl
                                ? `<div class="thumb" style="background-image:url('${esc(url(recipe.photoUrl))}')"></div>`
                                : '<div class="thumb">📷</div>'}
                            <input type="file" id="photo" accept="image/*" style="flex:1">
                            ${recipe.photoUrl ? '<button class="btn-danger btn-sm" id="remove-photo">Retirer</button>' : ''}
                        </div>
                    </div>

                    <div class="field">
                        <label for="tags">Tags (séparés par des virgules)</label>
                        <input id="tags" value="${esc(recipe.tags.join(', '))}" placeholder="rapide, végétarien">
                    </div>

                    <label class="row" style="gap:8px;margin-top:8px">
                        <input type="checkbox" id="isActive" style="width:22px;min-height:22px"
                               ${recipe.isActive ? 'checked' : ''}>
                        <span>Proposer cette recette dans les menus</span>
                    </label>
                </div>

                <div class="card">
                    <h2 style="margin-top:0">Ingrédients</h2>
                    <div id="lines">
                        ${lines.map((line, index) => `
                            <div class="ingredient-line" data-index="${index}">
                                <div class="grow">
                                    <input class="ing-name" list="ingredient-options" placeholder="Ingrédient"
                                           value="${esc(line.name || '')}">
                                </div>
                                <input class="ing-qty" type="number" inputmode="decimal" step="0.01" min="0"
                                       placeholder="Qté" value="${line.quantity ?? ''}">
                                <select class="ing-unit">
                                    <option value="">—</option>
                                    ${units.map(([key, meta]) => `
                                        <option value="${key}" ${line.unit === key ? 'selected' : ''}>
                                            ${esc(meta.label)}
                                        </option>`).join('')}
                                </select>
                                <button class="remove" aria-label="Supprimer la ligne">✕</button>
                            </div>`).join('')}
                    </div>
                    <datalist id="ingredient-options">
                        ${(state.data.ingredients || []).map((i) => `<option value="${esc(i.name)}"></option>`).join('')}
                    </datalist>
                    <button class="btn-secondary btn-block btn-sm" id="add-line">＋ Ajouter un ingrédient</button>
                </div>

                <button class="btn-primary btn-block" id="save">Enregistrer</button>
                ${state.params.id
                    ? '<button class="btn-danger btn-block" id="delete" style="margin-top:10px">Supprimer la recette</button>'
                    : ''}
            </div>` + tabbar('recipes');
        bindChrome();

        const syncLines = () => {
            app.querySelectorAll('.ingredient-line').forEach((row) => {
                const index = Number(row.dataset.index);
                lines[index] = {
                    ...lines[index],
                    name: row.querySelector('.ing-name').value,
                    quantity: row.querySelector('.ing-qty').value,
                    unit: row.querySelector('.ing-unit').value || null,
                };
            });
        };

        app.querySelectorAll('.ingredient-line .remove').forEach((btn) => {
            btn.onclick = () => {
                syncLines();
                lines.splice(Number(btn.closest('.ingredient-line').dataset.index), 1);
                render();
            };
        });

        app.querySelectorAll('.ing-name').forEach((input) => {
            input.onchange = () => {
                // Pré-remplit l'unité connue de l'ingrédient existant.
                const known = (state.data.ingredients || [])
                    .find((i) => i.name.toLowerCase() === input.value.trim().toLowerCase());
                const row = input.closest('.ingredient-line');
                const unitSelect = row.querySelector('.ing-unit');
                if (known && known.defaultUnit && !unitSelect.value) unitSelect.value = known.defaultUnit;
            };
        });

        app.querySelector('#add-line').onclick = () => {
            syncLines();
            lines.push({ name: '', quantity: '', unit: null });
            state.data.draftLines = lines;
            render();
            const inputs = app.querySelectorAll('.ing-name');
            if (inputs.length) inputs[inputs.length - 1].focus();
        };

        const photoInput = app.querySelector('#photo');
        photoInput.onchange = () => {
            if (!photoInput.files || !photoInput.files[0]) return;
            withLoader(async () => {
                const form = new FormData();
                form.append('photo', photoInput.files[0]);
                const res = await api('/uploads', { method: 'POST', body: form });
                recipe.photoUrl = res.url;
                toast('Photo ajoutée');
            });
        };

        const removePhoto = app.querySelector('#remove-photo');
        if (removePhoto) removePhoto.onclick = () => { recipe.photoUrl = null; render(); };

        app.querySelector('#save').onclick = () => {
            syncLines();
            const payload = {
                name: app.querySelector('#name').value.trim(),
                photoUrl: recipe.photoUrl,
                isActive: app.querySelector('#isActive').checked,
                tags: app.querySelector('#tags').value.split(',').map((t) => t.trim()).filter(Boolean),
                ingredients: lines
                    .filter((line) => (line.name || '').trim() !== '')
                    .map((line) => ({
                        name: line.name.trim(),
                        ingredientId: line.ingredientId,
                        quantity: line.quantity === '' || line.quantity === null ? null : Number(line.quantity),
                        unit: line.unit || null,
                    })),
            };
            withLoader(async () => {
                if (state.params.id) {
                    await api('/recipes/' + state.params.id, { method: 'PUT', body: payload });
                } else {
                    await api('/recipes', { method: 'POST', body: payload });
                }
                toast('Recette enregistrée');
                go('recipes');
            });
        };

        const del = app.querySelector('#delete');
        if (del) {
            del.onclick = () => {
                if (!confirm('Supprimer définitivement cette recette ?')) return;
                withLoader(async () => {
                    await api('/recipes/' + state.params.id, { method: 'DELETE' });
                    toast('Recette supprimée');
                    go('recipes');
                });
            };
        }
    }

    /* ----------------------------------------------------- Vue : historique */

    function renderHistory() {
        const menus = state.data.history || [];

        app.innerHTML = topbar('Historique', `${menus.length} menu(s)`, true) + `
            <div class="screen">
                ${menus.length === 0
                    ? '<div class="empty"><span class="ico">🗓️</span>Aucun menu validé pour le moment.</div>'
                    : menus.map((menu) => `
                        <div class="card" data-id="${menu.id}">
                            <div class="row">
                                <strong>Semaine du ${esc(formatWeek(menu.weekStart))}</strong>
                                <span class="spacer"></span>
                                <span class="badge ${menu.status === 'validated' ? '' : 'off'}">
                                    ${menu.status === 'validated' ? 'en cours' : 'archivé'}
                                </span>
                            </div>
                            <div class="muted">${menu.items.map((i) => esc(i.recipeName)).join(' · ')}</div>
                            <div class="row" style="margin-top:10px">
                                <button class="btn-secondary btn-sm" data-action="list">Liste de courses</button>
                                <button class="btn-ghost btn-sm" data-action="replay">Rejouer ce menu</button>
                            </div>
                        </div>`).join('')}
            </div>` + tabbar('history');
        bindChrome();

        app.querySelectorAll('.card[data-id]').forEach((card) => {
            const menuId = card.dataset.id;
            card.querySelector('[data-action="list"]').onclick = () => withLoader(async () => {
                const res = await api('/shopping-lists/by-menu/' + menuId);
                state.data.shoppingList = res.shoppingList;
                go('shopping', { id: res.shoppingList.id });
            });
            card.querySelector('[data-action="replay"]').onclick = () => withLoader(async () => {
                const res = await api(`/menus/${menuId}/replay`, { method: 'POST' });
                state.data.menu = res.menu;
                state.data.warning = res.warning;
                go('menu', { id: res.menu.id });
            });
        });
    }

    /* ------------------------------------------------------- Vue : réglages */

    function renderSettings() {
        const username = state.user ? state.user.username : '';

        app.innerHTML = topbar('Réglages', username, true) + `
            <div class="screen">
                <div class="card">
                    <h2 style="margin-top:0">Compte</h2>
                    <p class="muted">Un seul compte partagé : ${esc(username)}.</p>
                    <form id="password-form">
                        <div class="field">
                            <label for="currentPassword">Mot de passe actuel</label>
                            <input id="currentPassword" name="currentPassword" type="password" required
                                   autocomplete="current-password">
                        </div>
                        <div class="field">
                            <label for="newPassword">Nouveau mot de passe</label>
                            <input id="newPassword" name="newPassword" type="password" required
                                   autocomplete="new-password">
                        </div>
                        <button class="btn-secondary btn-block" type="submit">Changer le mot de passe</button>
                    </form>
                    <form id="username-form" style="margin-top:18px;border-top:1px solid var(--line);padding-top:14px">
                        <div class="field">
                            <label for="newUsername">Identifiant</label>
                            <input id="newUsername" name="username" required autocomplete="username"
                                   value="${esc(username)}">
                        </div>
                        <div class="field">
                            <label for="usernamePassword">Mot de passe (confirmation)</label>
                            <input id="usernamePassword" name="currentPassword" type="password" required
                                   autocomplete="current-password">
                        </div>
                        <button class="btn-ghost btn-block" type="submit">Changer l'identifiant</button>
                    </form>
                </div>
                <div class="card">
                    <h2 style="margin-top:0">Ingrédients</h2>
                    <p class="muted">${(state.data.ingredients || []).length} ingrédient(s) au référentiel.</p>
                    <button class="btn-ghost btn-block" id="go-catalog">Gérer les ingrédients</button>
                </div>
                <div class="card">
                    <h2 style="margin-top:0">Export</h2>
                    <p class="muted">Télécharge au format JSON les recettes avec leurs ingrédients
                       et le référentiel d'ingrédients.</p>
                    <a class="btn btn-secondary btn-block" id="export" href="${esc(url('api/export'))}"
                       download="food-export.json">⬇️ Exporter en JSON</a>
                </div>
                <button class="btn-danger btn-block" id="logout">Se déconnecter</button>
            </div>` + tabbar('settings');
        bindChrome();

        app.querySelector('#password-form').onsubmit = (event) => {
            event.preventDefault();
            const form = event.target;
            const body = Object.fromEntries(new FormData(form).entries());
            withLoader(async () => {
                await api('/auth/password', { method: 'POST', body });
                form.reset();
                toast('Mot de passe modifié');
            });
        };
        app.querySelector('#username-form').onsubmit = (event) => {
            event.preventDefault();
            const body = Object.fromEntries(new FormData(event.target).entries());
            withLoader(async () => {
                const res = await api('/auth/username', { method: 'POST', body });
                state.user = res.user;
                state.defaultUsername = res.user.username;
                toast('Identifiant modifié');
                render();
            });
        };
        app.querySelector('#go-catalog').onclick = () => go('catalog');
        app.querySelector('#logout').onclick = () => withLoader(async () => {
            await api('/auth/logout', { method: 'POST' });
            state.user = null;
            go('login');
        });
    }

    function renderCatalog() {
        const ingredients = state.data.ingredients || [];

        app.innerHTML = topbar('Ingrédients', `${ingredients.length} référencé(s)`, true) + `
            <div class="screen">
                ${ingredients.length === 0
                    ? '<div class="empty"><span class="ico">🥬</span>Aucun ingrédient pour le moment.</div>'
                    : ingredients.map((ingredient) => `
                        <div class="shop-item" data-id="${ingredient.id}">
                            <div style="flex:1;min-width:0">
                                <div class="label">${esc(ingredient.name)}</div>
                                <div class="sources">${esc(ingredient.categoryLabel)}
                                    · ${ingredient.usageCount} recette(s)</div>
                            </div>
                            <select class="cat-select">
                                ${Object.entries(state.categories).map(([key, label]) => `
                                    <option value="${key}" ${ingredient.category === key ? 'selected' : ''}>
                                        ${esc(label)}
                                    </option>`).join('')}
                            </select>
                            <button class="trash" aria-label="Supprimer">✕</button>
                        </div>`).join('')}
            </div>` + tabbar('settings');
        bindChrome();

        app.querySelectorAll('.shop-item[data-id]').forEach((row) => {
            const id = row.dataset.id;
            row.querySelector('.cat-select').onchange = (event) => withLoader(async () => {
                await api('/ingredients/' + id, { method: 'PUT', body: { category: event.target.value } });
                state.data.ingredients = (await api('/ingredients')).ingredients;
                toast('Rayon mis à jour');
            });
            row.querySelector('.trash').onclick = () => withLoader(async () => {
                await api('/ingredients/' + id, { method: 'DELETE' });
                state.data.ingredients = (await api('/ingredients')).ingredients;
                toast('Ingrédient supprimé');
            });
        });
    }

    /* --------------------------------------------------------- Chrome (UI) */

    function topbar(title, subtitle, showBack) {
        return `
            <header class="topbar">
                ${showBack && state.view !== 'home' ? '<button id="back-btn" aria-label="Retour">‹</button>' : ''}
                <h1>${esc(title)}${subtitle ? `<span class="subtitle">${esc(subtitle)}</span>` : ''}</h1>
                ${state.loading ? '<span class="spinner"></span>' : ''}
            </header>`;
    }

    function tabbar(active) {
        const tabs = [
            { key: 'home', icon: '🏠', label: 'Semaine' },
            { key: 'recipes', icon: '📖', label: 'Recettes' },
            { key: 'shopping', icon: '🛒', label: 'Courses' },
            { key: 'history', icon: '🗓️', label: 'Historique' },
            { key: 'settings', icon: '⚙️', label: 'Réglages' },
        ];
        return `<nav class="tabbar">${tabs.map((tab) => `
            <button data-tab="${tab.key}" class="${tab.key === active ? 'active' : ''}">
                <span class="ico">${tab.icon}</span>${tab.label}
            </button>`).join('')}</nav>`;
    }

    function bindChrome() {
        app.querySelectorAll('.tabbar button').forEach((btn) => {
            btn.onclick = () => go(btn.dataset.tab);
        });
        const backBtn = app.querySelector('#back-btn');
        if (backBtn) backBtn.onclick = () => back();
    }

    function debounce(fn, delay) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    }

    /* ------------------------------------------------------------- Routeur */

    const views = {
        login: renderAuth,
        setup: renderAuth,
        home: renderHome,
        menu: renderMenu,
        validate: renderValidate,
        shopping: renderShopping,
        recipes: renderRecipes,
        recipe: renderRecipeForm,
        history: renderHistory,
        settings: renderSettings,
        catalog: renderCatalog,
    };

    function renderLoadError() {
        app.innerHTML = topbar('Oups', '', true)
            + `<div class="screen">
                <div class="empty">
                    <div class="ico">📡</div>
                    <p>${esc(state.loadError)}</p>
                    <button class="btn-primary" id="retry-load">Réessayer</button>
                </div>
            </div>` + tabbar(state.view);
        bindChrome();
        app.querySelector('#retry-load').onclick = () => { state.loadError = null; render(); loadView(); };
    }

    function render() {
        if (state.loadError && state.view !== 'login' && state.view !== 'setup') {
            renderLoadError();
            return;
        }
        (views[state.view] || renderHome)();
    }

    async function bootstrapSession() {
        const res = await api('/auth/me');
        state.user = res.user;
        state.units = res.units;
        state.categories = res.categories;
    }

    (async function start() {
        try {
            await bootstrapSession();
            go('home');
        } catch (e) {
            try {
                const status = await api('/auth/status');
                state.defaultUsername = status.defaultUsername;
                go(status.needsSetup ? 'setup' : 'login');
            } catch (err) {
                go('login');
            }
        }
    })();
})();
