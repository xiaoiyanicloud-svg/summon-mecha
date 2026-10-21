@push('scripts')<script>
        document.querySelectorAll('[data-add-row]').forEach(function (button) {
            var container = document.querySelector('[data-repeat-container="'+button.dataset.addRow+'"]');
            function reindex() {
                Array.from(container.children).forEach(function (row,index) { row.querySelectorAll('[name]').forEach(function (input) { input.name=input.name.replace(/\[\d+\]/,'['+index+']'); }); });
            }
            function removeControl(row) {
                if (row.querySelector('[data-remove-row]')) return;
                var remove=document.createElement('button');remove.type='button';remove.dataset.removeRow='1';remove.className='secondary-button';remove.textContent='移除此项';row.appendChild(remove);
            }
            Array.from(container.children).forEach(removeControl);
            container.addEventListener('click',function(event){var remove=event.target.closest('[data-remove-row]');if(remove&&container.children.length>1){remove.parentElement.remove();reindex();}});
            button.addEventListener('click', function () {
                var count = container.children.length;
                if (count >= 50) return;
                var row = container.firstElementChild.cloneNode(true);
                row.querySelectorAll('[name]').forEach(function (input) { if(input.tagName==='SELECT'){var selected=Array.from(input.options).findIndex(function(option){return option.defaultSelected;});input.selectedIndex=Math.max(0,selected);}else input.value=input.type==='number' ? (input.defaultValue||'1') : ''; });
                container.appendChild(row);reindex();
            });
        });
</script>@endpush
