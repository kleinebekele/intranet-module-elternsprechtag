{{-- Absage-Fenster mit Mailvorschau, in der die Nachricht beim Tippen erscheint.
     Der Block muss zu Support\Ausfall::nachrichtHtml() passen. --}}
@once
<script>
    function ausfallFenster(vorlage, marke) {
        const escape = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

        return {
            offen: false,
            nachricht: '',
            block(t) {
                t = t.trim();
                if (t === '') return '';
                return '<div style="margin:0 0 16px;padding:12px 16px;background:#f3f4f6;border-left:4px solid #9ca3af;border-radius:4px;white-space:pre-line;">' + escape(t) + '</div>';
            },
            get vorschau() { return vorlage.split(marke).join(this.block(this.nachricht)); },
        };
    }
</script>
@endonce
