(() => {
  'use strict';

  const core = window.StuckbemaCore || {};
  const normalizeText = core.normalizeText || ((value) => String(value || '').replace(/\s+/g, ' ').trim());

  function defaultRenderItem(result) {
    const label = normalizeText(result.label || result.value);
    const meta = [result.name, result.email, result.phone]
      .map(normalizeText)
      .filter((value) => value && value !== label)
      .join(' · ');

    return {
      label,
      meta
    };
  }

  function createFieldAutocomplete({
    input,
    fieldType,
    endpoint,
    minChars = 1,
    debounceMs = 250,
    onSelect,
    renderItem = defaultRenderItem
  }) {
    if (!input || !endpoint || !fieldType) {
      return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'field-autocomplete';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const list = document.createElement('div');
    list.className = 'field-autocomplete-list';
    list.id = `${input.id || fieldType}-autocomplete-list`;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrapper.appendChild(list);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);

    const scope = core.createEventScope?.() || null;
    let timer = null;
    let controller = null;
    let results = [];
    let activeIndex = -1;

    function setExpanded(isExpanded) {
      list.hidden = !isExpanded;
      input.setAttribute('aria-expanded', String(isExpanded));
    }

    function close() {
      activeIndex = -1;
      setExpanded(false);
      input.removeAttribute('aria-activedescendant');
    }

    function setActive(index) {
      activeIndex = index;
      Array.from(list.children).forEach((option, optionIndex) => {
        const isActive = optionIndex === activeIndex;
        option.classList.toggle('is-active', isActive);
        option.setAttribute('aria-selected', String(isActive));
        if (isActive) {
          input.setAttribute('aria-activedescendant', option.id);
        }
      });
    }

    function selectResult(result) {
      if (!result) return;
      input.value = normalizeText(result.value || result.label);
      close();
      onSelect?.(result);
    }

    function renderResults(nextResults) {
      results = Array.isArray(nextResults) ? nextResults.slice(0, 10) : [];
      activeIndex = -1;
      list.replaceChildren();

      if (!results.length) {
        close();
        return;
      }

      results.forEach((result, index) => {
        const rendered = renderItem(result);
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'field-autocomplete-option';
        option.id = `${list.id}-option-${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');

        const label = document.createElement('span');
        label.className = 'field-autocomplete-label';
        label.textContent = rendered.label;
        option.appendChild(label);

        if (rendered.meta) {
          const meta = document.createElement('span');
          meta.className = 'field-autocomplete-meta';
          meta.textContent = rendered.meta;
          option.appendChild(meta);
        }

        option.dataset.index = String(index);
        list.appendChild(option);
      });

      setExpanded(true);
    }

    async function search(query) {
      controller?.abort();
      controller = new AbortController();
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set('field', fieldType);
      url.searchParams.set('q', query);

      try {
        const requestOptions = {
          signal: controller.signal,
          headers: { Accept: 'application/json' }
        };
        const response = typeof auth !== 'undefined' && auth?.fetch
          ? await auth.fetch(url.toString(), requestOptions)
          : await fetch(url, requestOptions);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(data.error || `Sökfel ${response.status}`);
        }
        renderResults(Array.isArray(data) ? data : data.results);
      } catch (error) {
        if (error.name !== 'AbortError') {
          console.error('Autocomplete-sökning misslyckades:', error);
        }
      }
    }

    const on = scope?.on.bind(scope) || ((target, type, handler, options) => {
      target.addEventListener(type, handler, options);
      return () => target.removeEventListener(type, handler, options);
    });
    const schedule = scope?.timeout.bind(scope) || window.setTimeout.bind(window);

    on(list, 'mousedown', (event) => {
      if (event.target.closest('.field-autocomplete-option')) {
        event.preventDefault();
      }
    });

    on(list, 'click', (event) => {
      const option = event.target.closest('.field-autocomplete-option');
      if (!option) {
        return;
      }

      selectResult(results[Number(option.dataset.index)]);
    });

    on(input, 'input', () => {
      const query = normalizeText(input.value);
      clearTimeout(timer);
      controller?.abort();

      if (query.length < minChars) {
        results = [];
        list.replaceChildren();
        close();
        return;
      }

      timer = schedule(() => search(query), debounceMs);
    });

    on(input, 'keydown', (event) => {
      if (list.hidden && !['ArrowDown', 'ArrowUp'].includes(event.key)) {
        return;
      }

      if (event.key === 'Escape') {
        close();
      } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        setActive(Math.min(activeIndex + 1, results.length - 1));
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        setActive(Math.max(activeIndex - 1, 0));
      } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        selectResult(results[activeIndex]);
      }
    });

    on(document, 'click', (event) => {
      if (!wrapper.contains(event.target)) {
        close();
      }
    });

    return {
      close,
      destroy() {
        clearTimeout(timer);
        controller?.abort();
        scope?.cleanup();
        wrapper.replaceWith(input);
      }
    };
  }

  window.StuckbemaFieldAutocomplete = Object.freeze({
    createFieldAutocomplete
  });
})();
