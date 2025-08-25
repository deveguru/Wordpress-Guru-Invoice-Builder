<?php
/**
 * Plugin Name: Guru Invoice Builder
 * Plugin URI: https://github.com/deveguru
 * Description: Professional invoice builder for Caspian Smart Security website
 * Version: 1.4.1
 * Author: Alireza Fatemi
 * Author URI: https://alirezafatemi.ir
 * Text Domain: guru-factor-builder
 */

if (!defined('ABSPATH')) {
    exit;
}

class GuruFactorBuilder {

    const GFB_VERSION = '1.4.1';
    private $table_name;
    private $upload_dir;
    private $upload_url;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'guru_invoices';
        $upload_info = wp_upload_dir();
        $this->upload_dir = $upload_info['basedir'] . '/Guru Factor Builder/';
        $this->upload_url = $upload_info['baseurl'] . '/Guru Factor Builder/';

        add_action('plugins_loaded', array($this, 'check_for_updates'));
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('wp_ajax_save_invoice', array($this, 'save_invoice_ajax'));
        add_action('wp_ajax_get_woocommerce_products', array($this, 'get_woocommerce_products_ajax'));
        add_action('wp_ajax_get_invoice_data', array($this, 'get_invoice_data_ajax'));
        add_action('wp_ajax_send_invoice_email', array($this, 'send_invoice_email_ajax'));
        add_action('wp_ajax_delete_invoice', array($this, 'delete_invoice_ajax'));

        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
    }

    public function check_for_updates() {
        $stored_version = get_option('gfb_version', '1.0.0');
        if (version_compare($stored_version, self::GFB_VERSION, '<')) {
            $this->run_upgrade_routine();
        }
    }

    public function activate_plugin() {
        $this->run_upgrade_routine();
    }
    
    public function run_upgrade_routine() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            invoice_number varchar(50) NOT NULL,
            customer_name varchar(255) NOT NULL,
            customer_phone varchar(20),
            customer_address text,
            invoice_date varchar(50) NOT NULL,
            items longtext NOT NULL,
            total_amount decimal(15,2) NOT NULL,
            discount_amount decimal(15,2) DEFAULT 0,
            tax_rate decimal(5,2) DEFAULT 0,
            currency varchar(10) DEFAULT 'ریال',
            file_path varchar(255) DEFAULT '' NOT NULL,
            status varchar(20) DEFAULT 'unpaid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if (get_option('gfb_company_name') === false) {
            update_option('gfb_company_name', 'شرکت کارا خدمات پوراطمینان');
            update_option('gfb_company_address', 'مازندران _شهرستان نوشهر _ بلوار شهید عمادالدین کریمی_ پاساژ علاءالدین_ واحد ۴۲۹');
            update_option('gfb_company_phone', '09368182353');
            update_option('gfb_bank_card', '5892101262602341');
            update_option('gfb_bank_account', '892301738209');
            update_option('gfb_bank_name', 'سپه');
            update_option('gfb_account_holder', 'احمد پوراطمینان');
            update_option('gfb_sheba_number', 'IR680150000000892301738209');
            update_option('gfb_default_currency', 'ریال');
        }
        
        update_option('gfb_version', self::GFB_VERSION);
    }
    
    public function init() {
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page('Guru Factor Builder', 'صدور فاکتور', 'manage_options', 'guru-factor-builder', array($this, 'render_invoice_builder_page'), 'dashicons-money-alt', 30);
        add_submenu_page('guru-factor-builder', 'فاکتورها', 'فاکتورها', 'manage_options', 'guru-factor-list', array($this, 'render_invoices_list_page'));
        add_submenu_page('guru-factor-builder', 'تنظیمات', 'تنظیمات', 'manage_options', 'guru-factor-settings', array($this, 'render_settings_page'));
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'guru-factor') !== false) {
            wp_enqueue_script('jquery');
            wp_enqueue_media();
        }
    }

    private function get_woocommerce_currency() {
        if (class_exists('WooCommerce')) {
            $woo_currency = get_woocommerce_currency();
            $currency_map = ['IRR' => 'ریال', 'IRT' => 'تومان', 'USD' => 'دلار', 'EUR' => 'یورو', 'GBP' => 'پوند'];
            return $currency_map[$woo_currency] ?? $woo_currency;
        }
        return get_option('gfb_default_currency', 'ریال');
    }

    private function get_currency_options() {
        return ['ریال' => 'ریال', 'تومان' => 'تومان', 'دلار' => 'دلار', 'یورو' => 'یورو', 'پوند' => 'پوند'];
    }

    public function render_invoice_builder_page() {
        ?>
        <div class="wrap gfb-wrap">
            <h1>صدور فاکتور جدید</h1>
            <div class="gfb-container">
                <form id="invoice-form" method="post">
                    <?php wp_nonce_field('gfb_invoice_nonce', '_gfb_nonce'); ?>
                    <div class="gfb-row">
                        <div class="gfb-col-6">
                            <h3>اطلاعات مشتری</h3>
                            <table class="form-table">
                                <tr><th><label for="customer_name">نام مشتری</label></th><td><input type="text" id="customer_name" name="customer_name" class="regular-text" required /></td></tr>
                                <tr><th><label for="customer_phone">شماره تماس</label></th><td><input type="text" id="customer_phone" name="customer_phone" class="regular-text" /></td></tr>
                                <tr><th><label for="customer_address">آدرس</label></th><td><textarea id="customer_address" name="customer_address" rows="3" class="large-text"></textarea></td></tr>
                                <tr><th><label for="invoice_date">تاریخ فاکتور</label></th><td><input type="text" id="invoice_date" name="invoice_date" class="regular-text" placeholder="روز/ماه/سال" value="<?php echo date('Y/m/d'); ?>"/></td></tr>
                            </table>
                        </div>
                        <div class="gfb-col-6">
                            <h3>تنظیمات فاکتور</h3>
                            <table class="form-table">
                                <tr><th><label for="invoice_number">شماره فاکتور</label></th><td><input type="text" id="invoice_number" name="invoice_number" class="regular-text" value="<?php echo $this->generate_invoice_number(); ?>" /></td></tr>
                                <tr><th><label for="status">وضعیت</label></th><td><select id="status" name="status"><option value="unpaid">تسویه نشده</option><option value="paid">تسویه شده</option><option value="partial">تسویه جزئی</option></select></td></tr>
                                <tr><th><label for="currency">واحد پول</label></th><td><select id="currency" name="currency"><?php foreach ($this->get_currency_options() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($key, $this->get_woocommerce_currency()); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                                <tr><th><label for="tax_rate">نرخ مالیات (%)</label></th><td><input type="number" id="tax_rate" name="tax_rate" class="small-text" value="0" step="0.01" /></td></tr>
                            </table>
                        </div>
                    </div>
                    <h3>اقلام فاکتور</h3>
                    <div class="gfb-items-section">
                        <div class="gfb-add-item-methods">
                            <button type="button" id="add-manual-item" class="button">افزودن دستی</button>
                            <?php if (class_exists('WooCommerce')): ?><button type="button" id="add-woo-item" class="button">افزودن از محصولات سایت</button><?php endif; ?>
                        </div>
                        <table id="items-table" class="wp-list-table widefat fixed striped">
                            <thead><tr><th>عنوان</th><th style="width: 80px;">مقدار</th><th style="width: 100px;">واحد</th><th>مبلغ واحد</th><th>تخفیف</th><th>جمع کل</th><th style="width: 80px;">عملیات</th></tr></thead>
                            <tbody id="items-tbody"></tbody>
                        </table>
                        <div class="gfb-totals">
                            <table class="form-table">
                                <tr><th>جمع:</th><td><span id="subtotal">0</span> <span id="currency-display"><?php echo $this->get_woocommerce_currency(); ?></span></td></tr>
                                <tr><th>جمع تخفیف‌ها:</th><td><span id="total-discount">0</span> <span class="currency-display"><?php echo $this->get_woocommerce_currency(); ?></span></td></tr>
                                <tr><th>مالیات:</th><td><span id="tax-amount">0</span> <span class="currency-display"><?php echo $this->get_woocommerce_currency(); ?></span></td></tr>
                                <tr><th><strong>مبلغ قابل پرداخت:</strong></th><td><strong><span id="final-total">0</span> <span class="currency-display"><?php echo $this->get_woocommerce_currency(); ?></span></strong></td></tr>
                            </table>
                        </div>
                    </div>
                    <div class="gfb-actions"><button type="button" id="save-invoice" class="button-primary">ذخیره و ایجاد فاکتور</button><button type="button" id="preview-invoice" class="button">پیش‌نمایش</button></div>
                </form>
            </div>
            <?php if (class_exists('WooCommerce')): ?>
            <div id="woo-products-modal" class="gfb-modal" style="display: none;"><div class="gfb-modal-content"><span class="gfb-close">&times;</span><h3>انتخاب محصول</h3><input type="text" id="woo-product-search" placeholder="جستجوی نام محصول..." /><div id="woo-products-list"><p class="loading-text">در حال بارگذاری...</p></div></div></div>
            <?php endif; ?>
            <div id="preview-modal" class="gfb-modal" style="display: none;"><div class="gfb-modal-content gfb-preview-modal"><span class="gfb-close">&times;</span><div id="invoice-preview"></div></div></div>
        </div>
        <style>.gfb-wrap h1{color:#161616}.gfb-container{background:white;padding:20px;border:1px solid #EEE;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}.gfb-row{display:flex;flex-wrap:wrap;gap:30px;margin-bottom:20px}.gfb-col-6{flex:1;min-width:300px}.gfb-items-section{border-top:2px solid #EEE;padding-top:20px;margin-top:20px}.gfb-add-item-methods{margin-bottom:15px}#items-table th,#items-table td{text-align:right}.gfb-totals{text-align:left;max-width:400px;margin-left:auto;margin-top:20px}.gfb-actions{text-align:left;margin-top:30px;border-top:1px solid #EEE;padding-top:20px}.gfb-actions button{font-size:14px}.gfb-modal{position:fixed;z-index:1001;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,.5)}.gfb-modal-content{background-color:#fefefe;margin:5% auto;padding:20px;border:1px solid #888;width:90%;max-width:800px;border-radius:5px;position:relative;max-height:80vh;display:flex;flex-direction:column}.gfb-preview-modal{max-width:1000px}.gfb-close{color:#aaa;position:absolute;left:15px;top:10px;font-size:28px;font-weight:bold;cursor:pointer}.remove-item{background:#BB000E;color:white;border:none;padding:5px 10px;border-radius:3px;cursor:pointer}#woo-product-search{width:100%;padding:10px;margin-bottom:15px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box}#woo-products-list{overflow-y:auto;flex-grow:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:15px}.woo-product-item{border:1px solid #EEE;padding:15px;border-radius:4px;transition:box-shadow .2s ease;cursor:pointer}.woo-product-item:hover{box-shadow:0 2px 8px rgba(0,0,0,.1)}.woo-product-item h4{margin:0 0 10px 0;font-size:14px;color:#161616}.woo-product-item p{margin:0;font-size:13px;color:#54595F}.loading-text{text-align:center;color:#54595F;width:100%;grid-column:1 / -1}</style>
        <script>
        jQuery(function($){let itemCounter=0;$('#add-manual-item').on('click',()=>addItemRow());$('#add-woo-item').on('click',()=>{ $('#woo-products-modal').show();loadWooCommerceProducts();});$('#save-invoice').on('click',saveInvoice);$('#preview-invoice').on('click',previewInvoice);$('.gfb-close').on('click',function(){ $(this).closest('.gfb-modal').hide();});$(document).on('input','.item-quantity, .item-price, .item-discount, #tax_rate',calculateTotals);$(document).on('change','#currency',updateCurrencyDisplay);$(document).on('click','.remove-item',function(){ $(this).closest('tr').remove();calculateTotals();});let searchTimeout;$('#woo-product-search').on('keyup',function(){ clearTimeout(searchTimeout);const searchTerm=$(this).val();searchTimeout=setTimeout(()=>loadWooCommerceProducts(searchTerm),300);});$(document).on('click','.woo-product-item',function(){ const item=$(this);addItemRow({title:item.data('title'),price:item.data('price')});$('#woo-products-modal').hide();});function updateCurrencyDisplay(){ const selectedCurrency=$('#currency').val();$('#currency-display, .currency-display').text(selectedCurrency);}function addItemRow(data={}){ itemCounter++;const rowHTML=`<tr class="item-row"><td><input type="text" name="items[${itemCounter}][title]" value="${data.title||''}" required /></td><td><input type="number" name="items[${itemCounter}][quantity]" class="item-quantity" value="${data.quantity||1}" min="1" required /></td><td><input type="text" name="items[${itemCounter}][unit]" value="${data.unit||'عدد'}" /></td><td><input type="number" name="items[${itemCounter}][price]" class="item-price" value="${data.price||0}" step="any" required /></td><td><input type="number" name="items[${itemCounter}][discount]" class="item-discount" value="${data.discount||0}" step="any" /></td><td class="item-total">0</td><td><button type="button" class="remove-item">حذف</button></td></tr>`;$('#items-tbody').append(rowHTML);calculateTotals();}function calculateTotals(){ let subtotal=0,totalDiscount=0;$('.item-row').each(function(){ const qty=parseFloat($(this).find('.item-quantity').val())||0;const price=parseFloat($(this).find('.item-price').val())||0;const discount=parseFloat($(this).find('.item-discount').val())||0;const itemTotal=(qty*price)-discount;$(this).find('.item-total').text(itemTotal.toLocaleString('fa-IR'));subtotal+=qty*price;totalDiscount+=discount;});const taxRate=parseFloat($('#tax_rate').val())||0;const taxAmount=(subtotal-totalDiscount)*(taxRate/100);const finalTotal=subtotal-totalDiscount+taxAmount;$('#subtotal').text(subtotal.toLocaleString('fa-IR'));$('#total-discount').text(totalDiscount.toLocaleString('fa-IR'));$('#tax-amount').text(taxAmount.toLocaleString('fa-IR'));$('#final-total').text(finalTotal.toLocaleString('fa-IR'));}function loadWooCommerceProducts(searchTerm=''){ $.ajax({url:ajaxurl,method:'POST',data:{action:'get_woocommerce_products',search:searchTerm,nonce:'<?php echo wp_create_nonce("gfb_woo_search_nonce"); ?>'},beforeSend:()=>$('#woo-products-list').html('<p class="loading-text">در حال جستجو...</p>'),success:function(response){ if(response.success){ let html=response.data.length===0?'<p class="loading-text">محصولی یافت نشد.</p>':response.data.map(p=>`<div class="woo-product-item" data-title="${p.title}" data-price="${p.price}"><h4>${p.title}</h4><p>قیمت: ${parseFloat(p.price).toLocaleString('fa-IR')} ${p.currency}</p></div>`).join('');$('#woo-products-list').html(html);}else{ $('#woo-products-list').html('<p class="loading-text">خطا در بارگذاری محصولات.</p>');}}});}function saveInvoice(){ $.ajax({url:ajaxurl,method:'POST',data:$('#invoice-form').serialize()+'&action=save_invoice',success:function(response){ if(response.success){ alert('فاکتور با موفقیت ذخیره و ایجاد شد.');window.location.href='<?php echo admin_url("admin.php?page=guru-factor-list"); ?>';}else{ alert('خطا در ذخیره فاکتور: '+response.data);}}});}function previewInvoice(){ const invoiceData=collectInvoiceData();$('#invoice-preview').html(generateInvoiceHTML(invoiceData));$('#preview-modal').show();}function collectInvoiceData(){ const items=[];$('.item-row').each(function(){ items.push({title:$(this).find('input[name*="[title]"]').val(),quantity:$(this).find('.item-quantity').val(),unit:$(this).find('input[name*="[unit]"]').val(),price:$(this).find('.item-price').val(),discount:$(this).find('.item-discount').val(),total:$(this).find('.item-total').text()});});return{customer_name:$('#customer_name').val(),customer_phone:$('#customer_phone').val(),customer_address:$('#customer_address').val(),invoice_number:$('#invoice_number').val(),invoice_date:$('#invoice_date').val(),status:$('#status option:selected').text(),currency:$('#currency').val(),items:items,subtotal:$('#subtotal').text(),total_discount:$('#total-discount').text(),tax_amount:$('#tax-amount').text(),final_total:$('#final-total').text()};}function generateInvoiceHTML(data){ let itemsHTML=data.items.map((item,index)=>`<tr><td>${index+1}</td><td>${item.title}</td><td>${item.quantity}</td><td>${item.unit}</td><td>${parseFloat(item.price).toLocaleString('fa-IR')}</td><td>${parseFloat(item.discount).toLocaleString('fa-IR')}</td><td>${item.total}</td></tr>`).join('');return`<div class="invoice-preview-container">${getInvoiceHTMLTemplate(data,itemsHTML)}</div>`;}updateCurrencyDisplay();});function getInvoiceHTMLTemplate(data,itemsHTML){ const company={name:'<?php echo esc_js(get_option("gfb_company_name")); ?>',address:'<?php echo esc_js(nl2br(get_option("gfb_company_address"))); ?>',phone:'<?php echo esc_js(get_option("gfb_company_phone")); ?>',logo:'<?php echo esc_js(get_option("gfb_logo_url")); ?>'};const bank={card:'<?php echo esc_js(get_option("gfb_bank_card")); ?>',sheba:'<?php echo esc_js(get_option("gfb_sheba_number")); ?>',name:'<?php echo esc_js(get_option("gfb_bank_name")); ?>',holder:'<?php echo esc_js(get_option("gfb_account_holder")); ?>'};const signature='<?php echo esc_js(get_option("gfb_signature_url")); ?>';return`<style>@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap');.invoice-preview-container{font-family:'Vazirmatn',Tahoma,sans-serif;direction:rtl;text-align:right;background:white;padding:25px;border:1px solid #ddd;color:#161616}.inv-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #BB000E;padding-bottom:15px;margin-bottom:20px}.inv-header .logo img{max-height:70px}.inv-header .company-info{text-align:left;font-size:.9em}.inv-title{background:#BB000E;color:white;padding:10px;text-align:center;font-size:1.2em;font-weight:bold;margin-bottom:20px}.inv-details,.inv-customer{display:flex;justify-content:space-between;margin-bottom:15px}.inv-table{width:100%;border-collapse:collapse;margin:20px 0;font-size:.95em}.inv-table th,.inv-table td{border:1px solid #EEE;padding:10px;text-align:center}.inv-table th{background:#54595F;color:white}.inv-totals-section{width:60%;margin-right:auto;text-align:left}.inv-totals-section div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #EEE}.inv-footer{display:flex;justify-content:space-between;margin-top:30px;padding-top:20px;border-top:1px solid #BB000E;font-size:.85em}.signature-img{max-height:80px;margin-top:10px}</style><div class="inv-header"><div class="logo">${company.logo?`<img src="${company.logo}" alt="Logo">`:''}</div><div class="company-info"><strong>${company.name}</strong><br>${company.address}<br>تلفن: ${company.phone}</div></div><div class="inv-title">پیش فاکتور</div><div class="inv-details"><div><strong>شماره فاکتور:</strong> ${data.invoice_number}</div><div><strong>تاریخ:</strong> ${data.invoice_date}</div></div><div class="inv-customer"><div><strong>طرف حساب:</strong> ${data.customer_name}</div><div><strong>شماره تماس:</strong> ${data.customer_phone}</div></div><div><strong>آدرس:</strong> ${data.customer_address}</div><table class="inv-table"><thead><tr><th>ردیف</th><th>عنوان</th><th>مقدار</th><th>واحد</th><th>مبلغ واحد</th><th>تخفیف</th><th>جمع</th></tr></thead><tbody>${itemsHTML}</tbody></table><div class="inv-totals-section"><div><span>جمع:</span><span>${data.subtotal} ${data.currency}</span></div><div><span>جمع تخفیف‌ها:</span><span>${data.total_discount} ${data.currency}</span></div><div><span>مالیات:</span><span>${data.tax_amount} ${data.currency}</span></div><div><strong>مبلغ قابل پرداخت:</strong><strong>${data.final_total} ${data.currency}</strong></div></div><div class="inv-footer"><div class="bank-info"><strong>اطلاعات پرداخت:</strong><br>بانک ${bank.name} بنام ${bank.holder}<br>کارت: ${bank.card}<br>شبا: ${bank.sheba}</div><div class="signatures" style="text-align:center;"><strong>امضای فروشنده</strong><br>${signature?`<img src="${signature}" alt="امضا" class="signature-img">`:'<br><br>...........................'}</div></div>`;}</script>
        <?php
    }
    
    public function render_settings_page() {
        if (isset($_POST['save_settings']) && check_admin_referer('gfb_settings_nonce')) {
            $this->save_settings(); echo '<div class="notice notice-success is-dismissible"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>تنظیمات پیش‌فرض فاکتور</h1>
            <form method="post">
                <?php wp_nonce_field('gfb_settings_nonce'); ?>
                <h2>اطلاعات شرکت</h2>
                <table class="form-table">
                    <tr><th><label for="gfb_company_name">نام شرکت</label></th><td><input type="text" id="gfb_company_name" name="gfb_company_name" value="<?php echo esc_attr(get_option('gfb_company_name')); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="gfb_company_address">آدرس</label></th><td><textarea id="gfb_company_address" name="gfb_company_address" rows="3" class="large-text"><?php echo esc_textarea(get_option('gfb_company_address')); ?></textarea></td></tr>
                    <tr><th><label for="gfb_company_phone">تلفن</label></th><td><input type="text" id="gfb_company_phone" name="gfb_company_phone" value="<?php echo esc_attr(get_option('gfb_company_phone')); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="gfb_logo_url">URL لوگو</label></th><td><input type="text" id="gfb_logo_url" name="gfb_logo_url" value="<?php echo esc_attr(get_option('gfb_logo_url')); ?>" class="regular-text" /><button type="button" class="button" id="upload-logo-btn">انتخاب تصویر</button></td></tr>
                    <tr><th><label for="gfb_signature_url">URL امضا/مهر</label></th><td><input type="text" id="gfb_signature_url" name="gfb_signature_url" value="<?php echo esc_attr(get_option('gfb_signature_url')); ?>" class="regular-text" /><button type="button" class="button" id="upload-signature-btn">انتخاب تصویر امضا</button></td></tr>
                </table>
                <h2>تنظیمات واحد پول</h2>
                <table class="form-table">
                    <tr><th><label for="gfb_default_currency">واحد پول پیش‌فرض</label></th><td><select id="gfb_default_currency" name="gfb_default_currency"><?php foreach ($this->get_currency_options() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($key, get_option('gfb_default_currency', 'ریال')); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description"><?php if (class_exists('WooCommerce')): ?>واحد پول فعلی ووکامرس: <strong><?php echo $this->get_woocommerce_currency(); ?></strong><?php else: ?>ووکامرس نصب نشده است.<?php endif; ?></p></td></tr>
                </table>
                <h2>اطلاعات بانکی</h2>
                <table class="form-table">
                    <tr><th><label for="gfb_bank_card">شماره کارت</label></th><td><input type="text" id="gfb_bank_card" name="gfb_bank_card" value="<?php echo esc_attr(get_option('gfb_bank_card')); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="gfb_bank_account">شماره حساب</label></th><td><input type="text" id="gfb_bank_account" name="gfb_bank_account" value="<?php echo esc_attr(get_option('gfb_bank_account')); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="gfb_bank_name">نام بانک</label></th><td><input type="text" id="gfb_bank_name" name="gfb_bank_name" value="<?php echo esc_attr(get_option('gfb_bank_name')); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="gfb_account_holder">نام صاحب حساب</label></th><td><input type="text" id="gfb_account_holder" name="gfb_account_holder" value="<?php echo esc_attr(get_option('gfb_account_holder')); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="gfb_sheba_number">شماره شبا (IBAN)</label></th><td><input type="text" id="gfb_sheba_number" name="gfb_sheba_number" value="<?php echo esc_attr(get_option('gfb_sheba_number')); ?>" class="regular-text ltr" /></td></tr>
                </table>
                <?php submit_button('ذخیره تنظیمات', 'primary', 'save_settings'); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            function setupUploader(buttonId, fieldId, title) {
                $(buttonId).on('click', function(e) {
                    e.preventDefault();
                    var uploader = wp.media({
                        title: title,
                        button: { text: 'انتخاب' },
                        multiple: false
                    }).on('select', function() {
                        var attachment = uploader.state().get('selection').first().toJSON();
                        $(fieldId).val(attachment.url);
                    }).open();
                });
            }
            setupUploader('#upload-logo-btn', '#gfb_logo_url', 'انتخاب لوگو');
            setupUploader('#upload-signature-btn', '#gfb_signature_url', 'انتخاب امضا/مهر');
        });
        </script>
        <?php
    }
    
    public function render_invoices_list_page() {
        global $wpdb;
        $invoices = $wpdb->get_results("SELECT id, invoice_number, customer_name, invoice_date, total_amount, currency, file_path, status FROM {$this->table_name} ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>فاکتورهای صادر شده</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>شماره فاکتور</th><th>مشتری</th><th>تاریخ</th><th>مبلغ کل</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="6">هیچ فاکتوری یافت نشد. برای شروع، یک <a href="<?php echo admin_url('admin.php?page=guru-factor-builder'); ?>">فاکتور جدید</a> ایجاد کنید.</td></tr>
                    <?php else: foreach ($invoices as $invoice): $file_url = $this->upload_url . basename($invoice->file_path); ?>
                        <tr id="invoice-row-<?php echo esc_attr($invoice->id); ?>">
                            <td><strong><?php echo esc_html($invoice->invoice_number); ?></strong></td>
                            <td><?php echo esc_html($invoice->customer_name); ?></td>
                            <td><?php echo esc_html($invoice->invoice_date); ?></td>
                            <td><?php echo number_format($invoice->total_amount); ?> <?php echo esc_html($invoice->currency); ?></td>
                            <td><?php $s=['paid'=>'تسویه شده','unpaid'=>'تسویه نشده','partial'=>'تسویه جزئی']; echo esc_html($s[$invoice->status]??$invoice->status); ?></td>
                            <td>
                                <button type="button" class="button view-invoice-btn" data-id="<?php echo esc_attr($invoice->id); ?>">مشاهده</button>
                                <?php if (!empty($invoice->file_path) && file_exists($invoice->file_path)): ?>
                                    <a href="<?php echo esc_url($file_url); ?>" target="_blank" class="button">دانلود</a>
                                <?php endif; ?>
                                <button type="button" class="button send-email-btn" data-id="<?php echo esc_attr($invoice->id); ?>">ارسال ایمیل</button>
                                <button type="button" class="button delete-invoice-btn" data-id="<?php echo esc_attr($invoice->id); ?>" style="color: #b32d2e;">حذف</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div id="gfb-view-modal" class="gfb-modal" style="display: none;"><div class="gfb-modal-content gfb-preview-modal"><span class="gfb-close">&times;</span><div id="invoice-view-content"></div></div></div>
        <div id="gfb-email-modal" class="gfb-modal" style="display: none;"><div class="gfb-modal-content" style="max-width: 500px;"><span class="gfb-close">&times;</span><h3>ارسال فاکتور از طریق ایمیل</h3><p>ایمیل گیرنده را وارد کنید:</p><input type="email" id="gfb-recipient-email" class="large-text" placeholder="name@example.com"><p class="gfb-email-status"></p><div class="gfb-actions"><button type="button" class="button" id="gfb-cancel-email-btn">انصراف</button><button type="button" class="button-primary" id="gfb-confirm-email-btn">ارسال</button></div></div></div>
        <style>.gfb-modal{position:fixed;z-index:1001;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,.5)}.gfb-modal-content{background-color:#fefefe;margin:5% auto;padding:20px;border:1px solid #888;width:90%;max-width:800px;border-radius:5px;position:relative;max-height:80vh;display:flex;flex-direction:column}.gfb-preview-modal{max-width:1000px}.gfb-close{color:#aaa;position:absolute;left:15px;top:10px;font-size:28px;font-weight:bold;cursor:pointer}#gfb-recipient-email{text-align:left;direction:ltr;margin-bottom:15px}.gfb-email-status{min-height:20px;font-weight:bold}.gfb-actions{text-align:left;margin-top:15px}</style>
        <script>
        jQuery(function($){const getInvoiceHTMLTemplate=(data)=>{ const itemsHTML=data.items.map((item,index)=>`<tr><td>${index+1}</td><td>${item.title}</td><td>${item.quantity}</td><td>${item.unit}</td><td>${parseFloat(item.price).toLocaleString('fa-IR')}</td><td>${parseFloat(item.discount).toLocaleString('fa-IR')}</td><td>${parseFloat(item.total).toLocaleString('fa-IR')}</td></tr>`).join('');const company={name:'<?php echo esc_js(get_option("gfb_company_name")); ?>',address:'<?php echo esc_js(nl2br(get_option("gfb_company_address"))); ?>',phone:'<?php echo esc_js(get_option("gfb_company_phone")); ?>',logo:'<?php echo esc_js(get_option("gfb_logo_url")); ?>'};const bank={card:'<?php echo esc_js(get_option("gfb_bank_card")); ?>',sheba:'<?php echo esc_js(get_option("gfb_sheba_number")); ?>',name:'<?php echo esc_js(get_option("gfb_bank_name")); ?>',holder:'<?php echo esc_js(get_option("gfb_account_holder")); ?>'};const signature='<?php echo esc_js(get_option("gfb_signature_url")); ?>';return `<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"><title>فاکتور ${data.invoice_number}</title><style>@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap');body{font-family:'Vazirmatn',Tahoma,sans-serif;direction:rtl;text-align:right;background:white;color:#161616;padding:15px}.invoice-container{max-width:800px;margin:auto}.inv-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #BB000E;padding-bottom:15px;margin-bottom:20px}.inv-header .logo img{max-height:70px}.inv-header .company-info{text-align:left;font-size:.9em}.inv-title{background:#BB000E;color:white;padding:10px;text-align:center;font-size:1.2em;font-weight:bold;margin-bottom:20px}.inv-details,.inv-customer{display:flex;justify-content:space-between;margin-bottom:15px}.inv-table{width:100%;border-collapse:collapse;margin:20px 0;font-size:.95em}.inv-table th,.inv-table td{border:1px solid #EEE;padding:10px;text-align:center}.inv-table th{background:#54595F;color:white}.inv-totals-section{width:60%;margin-right:auto;text-align:left}.inv-totals-section div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #EEE}.inv-footer{display:flex;justify-content:space-between;margin-top:30px;padding-top:20px;border-top:1px solid #BB000E;font-size:.85em}.signature-img{max-height:80px;margin-top:10px}@media print{body{padding:0}.button{display:none}}</style></head><body><div class="invoice-container"><div class="inv-header"><div class="logo">${company.logo?`<img src="${company.logo}" alt="Logo">`:''}</div><div class="company-info"><strong>${company.name}</strong><br>${company.address}<br>تلفن: ${company.phone}</div></div><div class="inv-title">پیش فاکتور</div><div class="inv-details"><div><strong>شماره فاکتور:</strong> ${data.invoice_number}</div><div><strong>تاریخ:</strong> ${data.invoice_date}</div></div><div class="inv-customer"><div><strong>طرف حساب:</strong> ${data.customer_name}</div><div><strong>شماره تماس:</strong> ${data.customer_phone}</div></div><div><strong>آدرس:</strong> ${data.customer_address}</div><table class="inv-table"><thead><tr><th>ردیف</th><th>عنوان</th><th>مقدار</th><th>واحد</th><th>مبلغ واحد</th><th>تخفیف</th><th>جمع</th></tr></thead><tbody>${itemsHTML}</tbody></table><div class="inv-totals-section"><div><strong>مبلغ قابل پرداخت:</strong><strong>${parseFloat(data.total_amount).toLocaleString('fa-IR')} ${data.currency}</strong></div></div><div class="inv-footer"><div class="bank-info"><strong>اطلاعات پرداخت:</strong><br>بانک ${bank.name} بنام ${bank.holder}<br>کارت: ${bank.card}<br>شبا: ${bank.sheba}</div><div class="signatures" style="text-align:center"><strong>امضای فروشنده</strong><br>${signature?`<img src="${signature}" alt="امضا" class="signature-img">`:'<br><br>...........................'}</div></div></div></body></html>`;};const fetchInvoiceData=(id,callback)=>{ $.post(ajaxurl,{action:'get_invoice_data',invoice_id:id,nonce:'<?php echo wp_create_nonce("gfb_view_nonce"); ?>'},response=>{ if(response.success){ response.data.items=JSON.parse(response.data.items);callback(response.data);}else{ alert('خطا: فاکتور یافت نشد.');}});};$('.view-invoice-btn').on('click',function(){ const invoiceId=$(this).data('id');fetchInvoiceData(invoiceId,data=>{ $('#invoice-view-content').html(getInvoiceHTMLTemplate(data));$('#gfb-view-modal').show();});});$('.send-email-btn').on('click',function(){ const invoiceId=$(this).data('id');$('#gfb-confirm-email-btn').data('id',invoiceId);$('#gfb-recipient-email').val('');$('.gfb-email-status').text('');$('#gfb-email-modal').show();});$('#gfb-confirm-email-btn').on('click',function(){ const button=$(this);const invoiceId=button.data('id');const recipient=$('#gfb-recipient-email').val();if(!recipient||!recipient.includes('@')){ $('.gfb-email-status').css('color','red').text('لطفا یک ایمیل معتبر وارد کنید.');return;}const statusEl=$('.gfb-email-status');statusEl.css('color','black').text('در حال ارسال...');button.prop('disabled',true);$.post(ajaxurl,{action:'send_invoice_email',invoice_id:invoiceId,email:recipient,nonce:'<?php echo wp_create_nonce("gfb_email_nonce"); ?>'},response=>{ if(response.success){ statusEl.css('color','green').text('ایمیل با موفقیت ارسال شد!');setTimeout(()=>{ $('#gfb-email-modal').hide();button.prop('disabled',false);},2000);}else{ statusEl.css('color','red').text('خطا در ارسال ایمیل: '+response.data);button.prop('disabled',false);}});});$('.delete-invoice-btn').on('click', function() { if (!confirm('آیا از حذف این فاکتور مطمئن هستید؟ این عملیات غیرقابل بازگشت است.')) return; const invoiceId = $(this).data('id'); $.post(ajaxurl, { action: 'delete_invoice', invoice_id: invoiceId, nonce: '<?php echo wp_create_nonce("gfb_delete_nonce"); ?>' }, response => { if (response.success) { $('#invoice-row-' + invoiceId).fadeOut(300, function() { $(this).remove(); }); } else { alert('خطا در حذف فاکتور: ' + response.data); } }); });$('.gfb-close, #gfb-cancel-email-btn').on('click',function(){ $(this).closest('.gfb-modal').hide();});});
        </script>
        <?php
    }

    public function get_invoice_data_ajax() {
        check_ajax_referer('gfb_view_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی.');
        global $wpdb;
        $invoice_id = intval($_POST['invoice_id']);
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $invoice_id));
        if ($invoice) wp_send_json_success($invoice); else wp_send_json_error('فاکتور یافت نشد.');
    }

    public function send_invoice_email_ajax() {
        check_ajax_referer('gfb_email_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی.');
        $invoice_id = intval($_POST['invoice_id']);
        $recipient_email = sanitize_email($_POST['email']);
        if (!is_email($recipient_email)) wp_send_json_error('آدرس ایمیل نامعتبر است.');
        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $invoice_id));
        if (!$invoice) wp_send_json_error('فاکتور یافت نشد.');
        $subject = sprintf('فاکتور شماره %s از طرف %s', $invoice->invoice_number, get_bloginfo('name'));
        $body = $this->generate_invoice_html($invoice, false); // No auto-print for email
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (wp_mail($recipient_email, $subject, $body, $headers)) wp_send_json_success(); else wp_send_json_error('WordPress قادر به ارسال ایمیل نبود.');
    }

    public function delete_invoice_ajax() {
        check_ajax_referer('gfb_delete_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی.');
        global $wpdb;
        $invoice_id = intval($_POST['invoice_id']);
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT file_path FROM {$this->table_name} WHERE id = %d", $invoice_id));
        if ($invoice && !empty($invoice->file_path) && file_exists($invoice->file_path)) {
            unlink($invoice->file_path);
        }
        $deleted = $wpdb->delete($this->table_name, ['id' => $invoice_id], ['%d']);
        if ($deleted) wp_send_json_success(); else wp_send_json_error('خطا در حذف از دیتابیس.');
    }
    
    private function generate_invoice_html($invoice_data, $with_print_script = true) {
        $items = json_decode($invoice_data->items, true);
        $itemsHTML = '';
        foreach($items as $index => $item) {
            $itemsHTML .= sprintf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', $index + 1, esc_html($item['title']), esc_html($item['quantity']), esc_html($item['unit']), number_format_i18n($item['price']), number_format_i18n($item['discount']), number_format_i18n($item['total']));
        }
        $company = ['name' => get_option('gfb_company_name'),'address' => nl2br(get_option('gfb_company_address')),'phone' => get_option('gfb_company_phone'),'logo' => get_option('gfb_logo_url')];
        $bank = ['card' => get_option('gfb_bank_card'),'sheba' => get_option('gfb_sheba_number'),'name' => get_option('gfb_bank_name'),'holder' => get_option('gfb_account_holder')];
        $signature = get_option('gfb_signature_url');
        ob_start();
        ?>
        <!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"><title>فاکتور <?php echo esc_html($invoice_data->invoice_number); ?></title><style>@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap');body{font-family:'Vazirmatn',Tahoma,sans-serif;direction:rtl;text-align:right;background:white;color:#161616;padding:15px}.invoice-container{max-width:800px;margin:auto}.inv-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #BB000E;padding-bottom:15px;margin-bottom:20px}.inv-header .logo img{max-height:70px}.inv-header .company-info{text-align:left;font-size:.9em}.inv-title{background:#BB000E;color:white;padding:10px;text-align:center;font-size:1.2em;font-weight:bold;margin-bottom:20px}.inv-details,.inv-customer{display:flex;justify-content:space-between;margin-bottom:15px}.inv-table{width:100%;border-collapse:collapse;margin:20px 0;font-size:.95em}.inv-table th,.inv-table td{border:1px solid #EEE;padding:10px;text-align:center}.inv-table th{background:#54595F;color:white}.inv-totals-section{width:60%;margin-right:auto;text-align:left}.inv-totals-section div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #EEE}.inv-footer{display:flex;justify-content:space-between;margin-top:30px;padding-top:20px;border-top:1px solid #BB000E;font-size:.85em}.signature-img{max-height:80px;margin-top:10px}@media print{body{padding:0;margin:0;}}</style></head><body><div class="invoice-container"><div class="inv-header"><div class="logo"><?php if($company['logo']) printf('<img src="%s" alt="Logo">', esc_url($company['logo'])); ?></div><div class="company-info"><strong><?php echo esc_html($company['name']); ?></strong><br><?php echo wp_kses_post($company['address']); ?><br>تلفن: <?php echo esc_html($company['phone']); ?></div></div><div class="inv-title">پیش فاکتور</div><div class="inv-details"><div><strong>شماره فاکتور:</strong> <?php echo esc_html($invoice_data->invoice_number); ?></div><div><strong>تاریخ:</strong> <?php echo esc_html($invoice_data->invoice_date); ?></div></div><div class="inv-customer"><div><strong>طرف حساب:</strong> <?php echo esc_html($invoice_data->customer_name); ?></div><div><strong>شماره تماس:</strong> <?php echo esc_html($invoice_data->customer_phone); ?></div></div><div><strong>آدرس:</strong> <?php echo esc_html($invoice_data->customer_address); ?></div><table class="inv-table"><thead><tr><th>ردیف</th><th>عنوان</th><th>مقدار</th><th>واحد</th><th>مبلغ واحد</th><th>تخفیف</th><th>جمع</th></tr></thead><tbody><?php echo $itemsHTML; ?></tbody></table><div class="inv-totals-section"><div><strong>مبلغ قابل پرداخت:</strong><strong><?php echo number_format_i18n($invoice_data->total_amount); ?> <?php echo esc_html($invoice_data->currency); ?></strong></div></div><div class="inv-footer"><div class="bank-info"><strong>اطلاعات پرداخت:</strong><br>بانک <?php echo esc_html($bank['name']); ?> بنام <?php echo esc_html($bank['holder']); ?><br>کارت: <?php echo esc_html($bank['card']); ?><br>شبا: <?php echo esc_html($bank['sheba']); ?></div><div class="signatures" style="text-align:center"><strong>امضای فروشنده</strong><br><?php if($signature) printf('<img src="%s" alt="امضا" class="signature-img">', esc_url($signature)); else echo '<br><br>...........................'; ?></div></div></div><?php if ($with_print_script): ?><script type="text/javascript">window.onload=function(){window.print();}</script><?php endif; ?></body></html>
        <?php
        return ob_get_clean();
    }

    public function save_invoice_ajax() {
        check_ajax_referer('gfb_invoice_nonce', '_gfb_nonce');
        global $wpdb;
        $items = []; $subtotal = 0; $total_discount = 0;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $qty = floatval($item['quantity']); $price = floatval($item['price']); $discount = floatval($item['discount']);
                $items[] = ['title' => sanitize_text_field($item['title']), 'quantity' => $qty, 'unit' => sanitize_text_field($item['unit']), 'price' => $price, 'discount' => $discount, 'total' => ($qty * $price) - $discount];
                $subtotal += $qty * $price; $total_discount += $discount;
            }
        }
        $tax_rate = isset($_POST['tax_rate']) ? floatval($_POST['tax_rate']) : 0;
        $tax_amount = ($subtotal - $total_discount) * ($tax_rate / 100);
        $final_total = $subtotal - $total_discount + $tax_amount;
        
        $data = [
            'invoice_number' => sanitize_text_field($_POST['invoice_number']),
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_phone' => sanitize_text_field($_POST['customer_phone']),
            'customer_address' => sanitize_textarea_field($_POST['customer_address']),
            'invoice_date' => sanitize_text_field($_POST['invoice_date']),
            'items' => wp_json_encode($items, JSON_UNESCAPED_UNICODE),
            'total_amount' => $final_total,
            'discount_amount' => $total_discount,
            'tax_rate' => $tax_rate,
            'currency' => sanitize_text_field($_POST['currency']),
            'status' => sanitize_text_field($_POST['status']),
            'file_path' => '' 
        ];
        
        $result = $wpdb->insert($this->table_name, $data);

        if ($result === false) {
            wp_send_json_error('خطا در ذخیره سازی فاکتور در دیتابیس. (' . $wpdb->last_error . ')');
            return;
        }

        $invoice_id = $wpdb->insert_id;
        $invoice_data_obj = (object) array_merge($data, ['id' => $invoice_id]);
        $invoice_html = $this->generate_invoice_html($invoice_data_obj, true); // Generate with print script
        $file_name = 'invoice-' . sanitize_file_name($data['invoice_number']) . '-' . $invoice_id . '.html';
        $file_path = $this->upload_dir . $file_name;
        
        if (file_put_contents($file_path, $invoice_html) !== false) {
            $wpdb->update($this->table_name, ['file_path' => $file_path], ['id' => $invoice_id]);
            wp_send_json_success('فاکتور با موفقیت ذخیره شد.');
        } else {
            wp_send_json_error('فاکتور در دیتابیس ذخیره شد اما فایل آن ایجاد نشد.');
        }
    }
    
    public function get_woocommerce_products_ajax() {
        check_ajax_referer('gfb_woo_search_nonce', 'nonce');
        if (!class_exists('WooCommerce')) wp_send_json_error('ووکامرس نصب نشده است.');
        $args = ['limit' => 50, 'status' => 'publish'];
        if (isset($_POST['search']) && !empty($_POST['search'])) $args['s'] = sanitize_text_field($_POST['search']);
        $products = wc_get_products($args);
        $woo_currency = $this->get_woocommerce_currency();
        $product_data = array_map(fn($p) => ['id' => $p->get_id(), 'title' => $p->get_name(), 'price' => $p->get_price(), 'currency' => $woo_currency], $products);
        wp_send_json_success($product_data);
    }
    
    private function save_settings() {
        $fields = ['gfb_company_name','gfb_company_address','gfb_company_phone','gfb_logo_url','gfb_signature_url','gfb_bank_card','gfb_bank_account','gfb_bank_name','gfb_account_holder','gfb_sheba_number','gfb_default_currency'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) update_option($field, sanitize_text_field($_POST[$field]));
        }
    }
    
    private function generate_invoice_number() {
        global $wpdb;
        $last_id = $wpdb->get_var("SELECT MAX(id) FROM {$this->table_name}");
        return 'CSS-' . date('Y') . '-' . str_pad($last_id + 1, 4, '0', STR_PAD_LEFT);
    }
}

new GuruFactorBuilder();
