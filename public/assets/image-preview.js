(() => {
  function isImageFile(file) {
    return file && file.type && file.type.startsWith('image/');
  }

  function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function createFileBadge(file) {
    const badge = document.createElement('div');
    badge.className = 'small text-muted';
    badge.textContent = file.name;
    return badge;
  }

  function applyInlinePreview(file, selector) {
    if (!file || !isImageFile(file) || !selector) return;
    let target = null;
    selector.split(',').some((part) => {
      target = document.querySelector(part.trim());
      return !!target;
    });
    if (!target) return;
    const url = URL.createObjectURL(file);
    if (target.tagName === 'IMG') {
      target.onload = () => URL.revokeObjectURL(url);
      target.src = url;
      return;
    }
    const img = document.createElement('img');
    img.alt = 'Vista previa';
    img.className = target.classList.contains('profile-photo-placeholder') ? 'profile-photo' : (target.className || '');
    if (target.id) {
      img.id = target.id === 'profile-photo-placeholder' ? 'profile-photo-img' : target.id;
    }
    img.onload = () => URL.revokeObjectURL(url);
    img.src = url;
    target.replaceWith(img);
  }

  function renderSinglePreview(file, container, inlineSelector, mode) {
    if (container) clearNode(container);
    if (!file) return;

    if (isImageFile(file)) {
      if (inlineSelector) {
        applyInlinePreview(file, inlineSelector);
      }
      if (mode === 'inline' || (inlineSelector && mode !== 'both')) {
        return;
      }
      if (!container) return;
      const img = document.createElement('img');
      img.alt = 'Vista previa';
      img.className = 'img-thumbnail mt-2';
      img.style.maxHeight = '180px';
      img.style.objectFit = 'cover';
      const url = URL.createObjectURL(file);
      img.onload = () => URL.revokeObjectURL(url);
      img.src = url;
      container.appendChild(img);
    } else if (container) {
      const box = document.createElement('div');
      box.className = 'mt-2 p-2 border rounded bg-light';
      box.appendChild(createFileBadge(file));
      container.appendChild(box);
    }
  }

  function renderMultiplePreview(files, container) {
    clearNode(container);
    if (!files || !files.length) return;

    const grid = document.createElement('div');
    grid.className = 'row g-3 mt-2';
    Array.from(files).forEach((file) => {
      const col = document.createElement('div');
      col.className = 'col-6 col-md-3';
      const wrap = document.createElement('div');
      wrap.className = 'border rounded p-2 bg-light text-center';
      if (isImageFile(file)) {
        const img = document.createElement('img');
        img.alt = 'Vista previa';
        img.className = 'img-fluid rounded';
        img.style.maxHeight = '140px';
        img.style.objectFit = 'cover';
        const url = URL.createObjectURL(file);
        img.onload = () => URL.revokeObjectURL(url);
        img.src = url;
        wrap.appendChild(img);
      }
      wrap.appendChild(createFileBadge(file));
      col.appendChild(wrap);
      grid.appendChild(col);
    });
    container.appendChild(grid);
  }

  function setupPreviewForInput(input) {
    const targetId = input.getAttribute('data-preview-target');
    const inlineSelector = input.getAttribute('data-preview-inline') || '';
    const container = targetId ? document.getElementById(targetId) : null;
    let mode = input.getAttribute('data-preview-mode') || '';
    if (!mode) {
      mode = input.multiple ? 'multiple' : (inlineSelector ? 'inline' : 'single');
    }
    if (!container && mode !== 'inline' && !inlineSelector) return;
    if (input.dataset.previewBound === '1') return;
    input.dataset.previewBound = '1';
    input.addEventListener('change', () => {
      if (mode === 'multiple') {
        renderMultiplePreview(input.files, container);
      } else {
        renderSinglePreview(input.files && input.files[0], container, inlineSelector, mode);
      }
    });
  }

  function initImagePreviews() {
    document.querySelectorAll('input[type="file"][data-preview-target], input[type="file"][data-preview-inline]').forEach(setupPreviewForInput);
  }

  window.FvdImagePreview = {
    bindInput: setupPreviewForInput,
    init: initImagePreviews,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initImagePreviews);
  } else {
    initImagePreviews();
  }
})();
