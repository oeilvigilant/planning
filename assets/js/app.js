// OV-Gestion — JS global

// Confirmation avant suppression
document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-confirm]');
    if (btn) {
        const msg = btn.dataset.confirm || 'Confirmer cette action ?';
        if (!confirm(msg)) e.preventDefault();
    }
});

// Prévisualisation photo agent
function previewPhoto(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const el = document.getElementById(previewId);
            if (el) el.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Formatage automatique N° Sécurité Sociale
document.addEventListener('input', function(e) {
    if (e.target.matches('[data-format="secu"]')) {
        let v = e.target.value.replace(/\s/g, '').replace(/[^0-9]/g, '');
        e.target.value = v.match(/.{1,1}/g)?.join('').replace(/(.{1})(.{2})(.{2})(.{2})(.{3})(.{3})(.{2})/, '$1 $2 $3 $4 $5 $6 $7') ?? v;
    }
});
