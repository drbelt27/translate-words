


        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#30B230',
                        'primary-hover': '#2FB22F',
                        canvas: '#F3F4F6',
                        border: '#e5e7eb',
                        surface: '#ffffff',
                    },
                    boxShadow: {
                        'content-wrapper': '0px 1px 1px 0px #0000000F, 0px 1px 2px 0px #0000001A',
                    }
                }
            }
        }
    

(() => {
  const product = document.getElementById('product-content-panel');
  const variations = document.getElementById('variations-panel');
  const tabs = [document.getElementById('product-content-tab'), document.getElementById('variations-tab')];
  const feedback = document.getElementById('prototype-feedback');
  const status = document.getElementById('translation-status');
  let dirty = false;
  const fields = [...document.querySelectorAll('input, textarea')];
  let saved = fields.map(field => field.value);
  const message = text => { feedback.hidden = false; feedback.textContent = text; };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => {
      product.hidden = index !== 0;
      variations.hidden = index !== 1;
      tabs.forEach((item, i) => { item.setAttribute('aria-selected', String(i === index)); item.classList.toggle('bg-gray-100', i === index); });
    });
    tab.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); const next = tabs[1 - index]; next.focus(); next.click(); }
    });
  });
  fields.forEach(field => field.addEventListener('input', () => { dirty = true; status.textContent = 'Unsaved changes'; }));
  document.getElementById('btn-save').addEventListener('click', () => {
    saved = fields.map(field => field.value); dirty = false; status.textContent = 'Saved in preview'; message('Preview only. The plugin and product data have not been changed.');
  });
  document.getElementById('btn-cancel').addEventListener('click', () => { fields.forEach((field, i) => { field.value = saved[i]; }); dirty = false; status.textContent = 'Needs review'; message('Unsaved preview changes discarded.'); });
  document.getElementById('ai-translate-all').addEventListener('click', () => { message('This preview does not call an AI provider. The implementation will use your existing Linguator provider configuration.'); });
  document.getElementById('preview-link').addEventListener('click', event => { event.preventDefault(); message('Storefront preview will open the same product in the selected language after implementation.'); });
  const language = document.querySelector('select');
  language.addEventListener('change', () => { language.value = 'de'; message(dirty ? 'Save or discard your changes before switching language. This draft demonstrates German.' : 'This draft demonstrates German. Other configured languages will use the same panel.'); });
  document.querySelectorAll('button').forEach(button => {
    if (!button.getAttribute('aria-label') && !button.textContent.trim()) {
      const icon = button.querySelector('iconify-icon');
      if (icon) button.setAttribute('aria-label', icon.getAttribute('icon').replace('lucide:', ''));
    }
  });
})();
