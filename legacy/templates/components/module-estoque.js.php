<script>
jQuery(document).ready(function($) {
    // Busca de produtos em tempo real
    $('#estoque-search').on('keyup', function() {
        var value = $(this).val().toLowerCase().trim();
        $('#estoque-products-list tr').filter(function() {
            var text = $(this).text().toLowerCase();
            // Melhora: busca também nos atributos data para garantir (ex: SKU, Nome)
            var name = $(this).data('product-name') ? $(this).data('product-name').toString().toLowerCase() : '';
            var type = $(this).data('product-type') ? $(this).data('product-type').toString().toLowerCase() : '';
            
            var match = text.indexOf(value) > -1 || name.indexOf(value) > -1 || type.indexOf(value) > -1;
            $(this).toggle(match);
        });
    });
});
</script>
