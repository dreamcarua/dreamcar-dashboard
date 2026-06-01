// === utm_mapping.js ===
// JavaScript для управління mappings CRM-ADS

(function($) {
    'use strict';

    const state = {
        mappings: [],
        uniqueValues: { crm: [], ads: [] },
        editingId: null
    };

    $(document).ready(function() {
        console.log('🔗 UTM Mapping Manager завантажено');
        initEventHandlers();
        loadMappings();
    });

    function initEventHandlers() {
        $('#createMappingBtn').on('click', () => openMappingForm());
        $('#closeMappingModalBtn, #cancelMappingBtn').on('click', () => closeMappingForm());

        $('#fieldType').on('change', function() {
            const fieldType = $(this).val();
            if (fieldType) {
                loadUniqueValues(fieldType);
            } else {
                $('#crmValue, #adsValue').html('<option value="">-- Спочатку виберіть тип поля --</option>');
            }
        });

        $('#crmValue').on('change', function() {
            const crmValue = $(this).val();
            if (crmValue && !$('#mergedName').val()) {
                $('#mergedName').val(crmValue);
            }
        });

        $('#saveMappingBtn').on('click', () => saveMapping());
    }

    function loadMappings() {
        console.log('📥 Завантаження mappings...');
        showLoader();

        $.ajax({
            url: 'api/utm_mapping/get_mappings.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                console.log('✅ Відповідь отримано:', response);
                hideLoader();
                if (response.success) {
                    state.mappings = response.mappings;
                    console.log('📊 Mappings:', state.mappings.length);
                    renderMappingsTable();
                } else {
                    console.error('❌ Помилка API:', response.error);
                    showNotification('Помилка: ' + response.error, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX помилка:', status, error);
                console.error('Response:', xhr.responseText);
                hideLoader();
                showNotification('Помилка з\'єднання: ' + error, 'error');
            }
        });
    }

    function renderMappingsTable() {
        console.log('🖼️ Рендеринг таблиці, mappings:', state.mappings.length);
        const tbody = $('#mappingsTableBody');
        tbody.empty();

        if (!state.mappings || state.mappings.length === 0) {
            console.log('⚠️ Немає mappings, показую empty state');
            $('#mappingsTable').hide();
            $('#mappingsTableEmpty').show();
            return;
        }

        console.log('✅ Є mappings, рендерю таблицю');

        $('#mappingsTable').show();
        $('#mappingsTableEmpty').hide();

        state.mappings.forEach(function(mapping) {
            console.log('➕ Додаю рядок:', mapping.id, mapping.crm_value, '→', mapping.ads_value);

            const row = $('<tr>').html(`
                <td><span class="badge" style="background: #6366f1; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">${formatFieldType(mapping.field_type)}</span></td>
                <td><code style="background: rgba(59, 130, 246, 0.2); padding: 4px 8px; border-radius: 4px;">🔵 ${escapeHtml(mapping.crm_value)}</code></td>
                <td><code style="background: rgba(251, 191, 36, 0.2); padding: 4px 8px; border-radius: 4px;">🟡 ${escapeHtml(mapping.ads_value)}</code></td>
                <td><strong>${escapeHtml(mapping.merged_name)}</strong></td>
                <td style="font-size: 0.85rem;">${escapeHtml(mapping.notes || '—')}</td>
                <td style="font-size: 0.85rem;">${formatDate(mapping.created_at)}</td>
                <td>
                    <button class="btn btn-sm btn-outline edit-btn" data-id="${mapping.id}" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">✏️</button>
                    <button class="btn btn-sm delete-btn" data-id="${mapping.id}" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; background: #ef4444; color: white;">🗑️</button>
                </td>
            `);

            tbody.append(row);
        });

        console.log('✅ Рендеринг завершено, tbody має рядків:', tbody.children().length);

        // Force показати ВСЮ структуру
        $('.content-section').attr('style', 'display: block !important; visibility: visible !important;');
        $('.table-container').attr('style', 'display: block !important; visibility: visible !important;');
        $('#mappingsTable').attr('style', 'display: table !important; visibility: visible !important;');
        $('.content-wrapper').attr('style', 'display: block !important; visibility: visible !important;');

        console.log('🔍 Таблиця видима?', $('#mappingsTable').is(':visible'));
        console.log('🔍 Container видимий?', $('.table-container').is(':visible'));
        console.log('🔍 Content section видима?', $('.content-section').is(':visible'));
        console.log('🔍 Content wrapper видимий?', $('.content-wrapper').is(':visible'));

        $('.edit-btn').on('click', function() {
            editMapping($(this).data('id'));
        });

        $('.delete-btn').on('click', function() {
            deleteMapping($(this).data('id'));
        });
    }

    function loadUniqueValues(fieldType) {
        showLoader();

        $.ajax({
            url: 'api/utm_mapping/get_unique_values.php',
            type: 'GET',
            data: { field_type: fieldType },
            dataType: 'json',
            success: function(response) {
                hideLoader();
                if (response.success) {
                    state.uniqueValues.crm = response.crm_values;
                    state.uniqueValues.ads = response.ads_values;
                    populateDropdown('#crmValue', state.uniqueValues.crm);
                    populateDropdown('#adsValue', state.uniqueValues.ads);
                } else {
                    showNotification('Помилка: ' + response.error, 'error');
                }
            },
            error: function() {
                hideLoader();
                showNotification('Помилка з\'єднання', 'error');
            }
        });
    }

    function populateDropdown(selector, values) {
        const select = $(selector);
        select.empty().append('<option value="">-- Виберіть --</option>');
        values.forEach(v => select.append(`<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`));
    }

    function openMappingForm(mappingId = null) {
        state.editingId = mappingId;

        if (mappingId) {
            const mapping = state.mappings.find(m => m.id == mappingId);
            if (!mapping) return;

            $('#modalTitle').text('Редагувати відповідність');
            $('#mappingId').val(mapping.id);
            $('#fieldType').val(mapping.field_type).trigger('change');

            setTimeout(() => {
                $('#crmValue').val(mapping.crm_value);
                $('#adsValue').val(mapping.ads_value);
                $('#mergedName').val(mapping.merged_name);
                $('#notes').val(mapping.notes);
            }, 500);
        } else {
            $('#modalTitle').text('Створити відповідність');
            $('#mappingForm')[0].reset();
            $('#mappingId').val('');
        }

        $('#mappingFormModal').addClass('active');
    }

    function closeMappingForm() {
        $('#mappingFormModal').removeClass('active');
        state.editingId = null;
    }

    function saveMapping() {
        if (!$('#mappingForm')[0].checkValidity()) {
            showNotification('Заповніть всі обов\'язкові поля', 'error');
            return;
        }

        const data = {
            id: $('#mappingId').val() || null,
            field_type: $('#fieldType').val(),
            crm_value: $('#crmValue').val(),
            ads_value: $('#adsValue').val(),
            merged_name: $('#mergedName').val(),
            notes: $('#notes').val()
        };

        showLoader();

        $.ajax({
            url: 'api/utm_mapping/save_mapping.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            dataType: 'json',
            success: function(response) {
                hideLoader();
                if (response.success) {
                    showNotification(response.message, 'success');
                    closeMappingForm();
                    loadMappings();
                } else {
                    showNotification('Помилка: ' + response.error, 'error');
                }
            },
            error: function() {
                hideLoader();
                showNotification('Помилка з\'єднання', 'error');
            }
        });
    }

    function editMapping(id) {
        openMappingForm(id);
    }

    function deleteMapping(id) {
        if (!confirm('Видалити це відповідність?')) return;

        showLoader();

        $.ajax({
            url: 'api/utm_mapping/delete_mapping.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                hideLoader();
                if (response.success) {
                    showNotification(response.message, 'success');
                    loadMappings();
                } else {
                    showNotification('Помилка: ' + response.error, 'error');
                }
            },
            error: function() {
                hideLoader();
                showNotification('Помилка з\'єднання', 'error');
            }
        });
    }

    function formatFieldType(fieldType) {
        const labels = {
            'utm_source': 'Source',
            'utm_medium': 'Medium',
            'utm_campaign': 'Campaign',
            'utm_term': 'Term',
            'utm_content': 'Content'
        };
        return labels[fieldType] || fieldType;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const date = new Date(dateStr);
        return date.toLocaleDateString('uk-UA', {day: '2-digit', month: '2-digit', year: 'numeric'});
    }

    function escapeHtml(text) {
        if (!text) return '';
        return $('<div>').text(text).html();
    }

    function showLoader() {
        $('#loaderOverlay').fadeIn(200);
    }

    function hideLoader() {
        $('#loaderOverlay').fadeOut(200);
    }

    function showNotification(message, type = 'info') {
        const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
        const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };

        const notification = $('<div>')
            .css({
                position: 'fixed',
                top: '1rem',
                right: '1rem',
                padding: '1rem 1.5rem',
                background: 'var(--card-bg)',
                border: '1px solid var(--border-color)',
                borderLeft: `4px solid ${colors[type]}`,
                borderRadius: '12px',
                boxShadow: '0 10px 30px rgba(0,0,0,0.3)',
                zIndex: 10000,
                minWidth: '300px',
                opacity: 0,
                transform: 'translateX(100%)',
                transition: 'all 0.3s ease'
            })
            .html(`<strong>${icons[type]}</strong> ${message}`);

        $('#notificationsContainer').append(notification);

        setTimeout(() => notification.css({ opacity: 1, transform: 'translateX(0)' }), 10);
        setTimeout(() => {
            notification.css({ opacity: 0, transform: 'translateX(100%)' });
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

})(jQuery);
