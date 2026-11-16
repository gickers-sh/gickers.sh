/**
  * codes.sh - Web HTTP Viewer
  * Lightweight vanilla JavaScript: 1-click clipboard copy, SSH CLI palette filtering,
  * expandable command cards (live argument/option editing + ssh:// links), and tab switching.
  * Strict CSP compliant ('self'), zero dependencies.
  */
(() => {
  'use strict';

  // Clipboard copy with visual feedback
  const copyToClipboard = async (text, button) => {
    if (!text) return;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      }
      showFeedback(button);
    } catch (err) {
      console.error('Failed to copy to clipboard', err);
    }
  };

  const showFeedback = (button) => {
    if (!button) return;
    button.classList.add('copied');
    const label = button.querySelector('.copy-label');
    const oldText = label ? label.textContent : '';
    if (label) label.textContent = 'Copied!';

    clearTimeout(button._copyTimeout);
    button._copyTimeout = setTimeout(() => {
      button.classList.remove('copied');
      if (label) label.textContent = oldText || 'Copy';
    }, 2000);
  };

  const initCopyButtons = () => {
    // Auto-populate icons in empty .copy-btn elements
    document.querySelectorAll('.copy-btn').forEach((btn) => {
      if (!btn.querySelector('.copy-icon')) {
        btn.insertAdjacentHTML('afterbegin', '<svg class="copy-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg><svg class="check-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>');
      }
    });

    // Wire up buttons that have data-copy attribute
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-copy]');
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        copyToClipboard(btn.getAttribute('data-copy'), btn);
        return;
      }

      // Clicking directly on clone code box copies it
      const cloneBox = e.target.closest('.clone-box');
      if (cloneBox) {
        const code = cloneBox.querySelector('code');
        const btn = cloneBox.querySelector('.copy-btn');
        if (code) {
          copyToClipboard(code.textContent.trim(), btn);
        }
        return;
      }

      // Clicking on a CLI card action box copies its command
      const cardAction = e.target.closest('.cli-card-action');
      if (cardAction) {
        const code = cardAction.querySelector('.cli-code');
        const btn = cardAction.querySelector('.copy-btn');
        if (code) {
          copyToClipboard(code.textContent.trim(), btn);
        }
      }
    });

    // Add 1-click copy buttons to all code blocks in the README
    document.querySelectorAll('.readme pre').forEach((pre) => {
      if (pre.querySelector('.pre-copy-btn')) return;

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'copy-btn pre-copy-btn';
      btn.setAttribute('aria-label', 'Copy code to clipboard');
      btn.setAttribute('title', 'Copy code');
      btn.innerHTML = '<svg class="copy-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg><svg class="check-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg><span class="copy-label">Copy</span>';

      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const code = pre.querySelector('code') || pre;
        const text = code.innerText || code.textContent;
        copyToClipboard(text.trim(), btn);
      });

      pre.appendChild(btn);
    });
  };

  const initCliPalette = () => {
    const palette = document.querySelector('#commands, .cli-palette-section');
    if (!palette) return;

    const tabs = palette.querySelectorAll('.cli-tab');
    const search = palette.querySelector('.cli-search');
    const cards = palette.querySelectorAll('.cli-command-card');

    let activeFilter = 'all';
    let searchQuery = '';

    const filterCards = () => {
      cards.forEach((card) => {
        const category = card.getAttribute('data-category');
        const text = card.textContent.toLowerCase();

        const matchesFilter = activeFilter === 'all' || category === activeFilter;
        const matchesSearch = !searchQuery || text.includes(searchQuery);

        if (matchesFilter && matchesSearch) {
          card.classList.remove('hidden');
        } else {
          card.classList.add('hidden');
        }
      });
    };

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabs.forEach((t) => t.classList.remove('active'));
        tab.classList.add('active');
        activeFilter = tab.getAttribute('data-filter') || 'all';
        filterCards();
      });
    });

    if (search) {
      search.addEventListener('input', (e) => {
        searchQuery = e.target.value.trim().toLowerCase();
        filterCards();
      });
    }
  };

  const initCliViewToggle = () => {
    const grids = document.querySelectorAll('.cli-command-grid');
    if (!grids.length) return;
    const viewBtns = document.querySelectorAll('.cli-view-btn');
    if (!viewBtns.length) return;

    const setView = (mode) => {
      viewBtns.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-view') === mode));
      grids.forEach((grid) => {
        if (mode === 'list') {
          grid.classList.add('cli-view-list');
        } else {
          grid.classList.remove('cli-view-list');
        }
      });
      try { localStorage.setItem('codes_cli_view', mode); } catch (_) {}
    };

    const saved = (() => {
      try { return localStorage.getItem('codes_cli_view'); } catch (_) { return null; }
    })();
    if (saved === 'list') setView('list');

    viewBtns.forEach((btn) => {
      btn.addEventListener('click', () => setView(btn.getAttribute('data-view')));
    });
  };

  // Expandable command cards: edit arguments/options and rebuild the command,
  // Expandable command cards: edit arguments/options and rebuild the command
  // and the copy payload live.
  const initCliEditors = () => {
    const grid = document.querySelector('.cli-command-grid');
    if (!grid) return;

    const prefix = grid.getAttribute('data-prefix') || '';

    // Mirror RepoWebController::shellQuote().
    const quote = (v) => /^[A-Za-z0-9_@%+=:,.\/-]+$/.test(v) ? v : "'" + v.replace(/'/g, "'\\''") + "'";

    const buildRemote = (card) => {
      let out = card.getAttribute('data-base') || '';
      card.querySelectorAll('.cli-field-input').forEach((el) => {
        const kind = el.getAttribute('data-kind');
        const flag = el.getAttribute('data-flag') || '';
        if (kind === 'bool') {
          if (el.checked) out += ' ' + flag;
          return;
        }
        const value = (el.value || '').trim();
        if (kind === 'flag' || kind === 'choice-flag') {
          if (value) out += ' ' + flag + ' ' + quote(value);
        } else {
          out += ' ' + (value ? quote(value) : '<' + el.getAttribute('data-key') + '>');
        }
      });
      return out;
    };

    const update = (card) => {
      const remote = buildRemote(card);
      const full = (prefix ? prefix + ' ' : '') + remote;
      const code = card.querySelector('.cli-card-action .cli-code');
      if (code) code.textContent = full;
      const copy = card.querySelector('.copy-btn');
      if (copy) copy.setAttribute('data-copy', full);
    };

    const toggle = (card) => {
      const detail = card.querySelector('.cli-card-detail');
      const info = card.querySelector('.cli-card-info');
      const open = card.classList.toggle('open');
      if (detail) detail.hidden = !open;
      if (info) info.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    grid.querySelectorAll('.cli-command-card.has-detail').forEach((card) => {
      const info = card.querySelector('.cli-card-info');
      if (info) {
        info.addEventListener('click', () => toggle(card));
        info.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggle(card);
          }
        });
      }
      card.querySelectorAll('.cli-field-input').forEach((el) => {
        el.addEventListener('input', () => update(card));
        el.addEventListener('change', () => update(card));
      });
    });
  };

  const initViewTabs = () => {
    const tabsNav = document.querySelector('.tabs');
    if (!tabsNav) return;

    const tabLinks = tabsNav.querySelectorAll('a[data-tab]');
    const tabContents = document.querySelectorAll('.tab-content');
    const statusSummary = document.getElementById('status-summary');

    const activateTab = (tabName, updateHash = false) => {
      let matched = false;
      tabLinks.forEach((link) => {
        const target = link.getAttribute('data-tab');
        if (target === tabName) {
          link.classList.add('active');
          matched = true;
        } else {
          link.classList.remove('active');
        }
      });

      if (!matched && tabLinks.length > 0) {
        tabLinks[0].classList.add('active');
        tabName = tabLinks[0].getAttribute('data-tab');
      }

      tabContents.forEach((content) => {
        if (content.id === tabName) {
          content.classList.add('active');
        } else {
          content.classList.remove('active');
        }
      });

      if (statusSummary) {
        statusSummary.textContent = tabName === 'readme' ? 'README · HEAD' : 'Commands · HEAD';
      }

      if (updateHash && window.location.hash !== '#' + tabName) {
        history.replaceState(null, '', '#' + tabName);
      }
    };

    tabLinks.forEach((link) => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const target = link.getAttribute('data-tab');
        activateTab(target, true);
      });
    });

    // Activate based on URL hash if present (#readme or #commands)
    const initialHash = (window.location.hash || '').replace('#', '');
    if (initialHash === 'readme' || initialHash === 'commands') {
      activateTab(initialHash, false);
    } else {
      activateTab('commands', false);
    }

    window.addEventListener('hashchange', () => {
      const hash = (window.location.hash || '').replace('#', '');
      if (hash === 'readme' || hash === 'commands') {
        activateTab(hash, false);
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initCopyButtons();
      initCliPalette();
      initCliViewToggle();
      initCliEditors();
      initViewTabs();
    });
  } else {
    initCopyButtons();
    initCliPalette();
    initCliViewToggle();
    initCliEditors();
    initViewTabs();
  }
})();
