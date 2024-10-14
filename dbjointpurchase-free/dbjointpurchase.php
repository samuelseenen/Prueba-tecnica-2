<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    DevBlinders <soporte@devblinders.com>
 * @copyright Copyright (c) DevBlinders
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Core\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

class Dbjointpurchase extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        if (file_exists(dirname(__FILE__) . '/premium/DbPremium.php')) {
            require_once(dirname(__FILE__) . '/premium/DbPremium.php');
            $this->premium = 1;
        } else {
            $this->premium = 0;
        }

        $this->name = 'dbjointpurchase';
        $this->tab = 'front_office_features';
        $this->version = '1.0.1';
        $this->author = 'DevBlinders';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('DB Joint Purchase');
        $this->description = $this->l('Compra conjunta de los productos');
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install() &&
            $this->installDb() &&
            $this->registerHook('displayHeader') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('displayFooterProduct');
    }

    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallDb();
    }

    protected function installDb()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "dbjointpurchase_manual` (
                    `id_product` int(10) unsigned NOT NULL,
                    `id_related_product` int(10) unsigned NOT NULL,
                    PRIMARY KEY (`id_product`, `id_related_product`)
                ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

        return Db::getInstance()->execute($sql);
    }

    protected function uninstallDb()
    {
        $sql = "DROP TABLE IF EXISTS `" . _DB_PREFIX_ . "dbjointpurchase_manual`;";
        return Db::getInstance()->execute($sql);
    }

    public function getContent()
    {
        if (((bool)Tools::isSubmit('submitDbjointpurchaseModule')) == true) {
            $this->postProcess();
        }

        return $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitDbjointpurchaseModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        $products = Product::getProducts($this->context->language->id, 0, 0, 'name', 'asc');
        $options = [];
        foreach ($products as $product) {
            $options[] = ['id_option' => $product['id_product'], 'name' => $product['name']];
        }

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'color',
                        'label' => $this->l('Color general'),
                        'name' => 'DBJOINT_COLOR',
                        'class' => 'disabled',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Productos excluidos'),
                        'name' => 'DBJOINT_EXCLUDE',
                        'class' => 'disabled',
                    ),
                    array(
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Productos relacionados manualmente'),
                        'name' => 'DBJOINT_MANUAL_PRODUCTS',
                        'options' => array(
                            'query' => $options,
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            'DBJOINT_COLOR' => Configuration::get('DBJOINT_COLOR'),
            'DBJOINT_EXCLUDE' => Configuration::get('DBJOINT_EXCLUDE'),
            'DBJOINT_MANUAL_PRODUCTS' => $this->getRelatedProductsManual(),
        );
    }

    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        $this->saveRelatedProductsManual(Tools::getValue('DBJOINT_MANUAL_PRODUCTS'));
    }

    protected function saveRelatedProductsManual($relatedProducts)
    {
        $id_product = Tools::getValue('id_product'); // Get current product ID
        Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'dbjointpurchase_manual WHERE id_product = ' . (int)$id_product);
        
        if (is_array($relatedProducts) && count($relatedProducts) > 0) {
            foreach ($relatedProducts as $related_product) {
                Db::getInstance()->insert('dbjointpurchase_manual', [
                    'id_product' => (int)$id_product,
                    'id_related_product' => (int)$related_product,
                ]);
            }
        }
    }

    protected function getRelatedProductsManual($id_product = null)
    {
        if (!$id_product) {
            $id_product = Tools::getValue('id_product'); // Get current product ID
        }

        $sql = 'SELECT id_related_product FROM ' . _DB_PREFIX_ . 'dbjointpurchase_manual WHERE id_product = ' . (int)$id_product;
        return array_column(Db::getInstance()->executeS($sql), 'id_related_product');
    }

    protected function isProductExcluded($productId)
    {
        $excluded = Configuration::get('DBJOINT_EXCLUDE');
        $excludedProducts = array_map('trim', explode(',', $excluded));
        return in_array($productId, $excludedProducts);
    }

    public function hookDisplayHeader()
    {
        $this->context->controller->addJS($this->_path . 'views/js/dbjointpurchase.js');
        $this->context->controller->addCSS($this->_path . 'views/css/dbjointpurchase.css');
    }

    public function hookDisplayFooterProduct($params)
    {
        $id_product = $params['product']->id;
        $related_products = $this->getProductsGenerate($id_product);

        if ($related_products) {
            $this->smarty->assign('related_products', $related_products);
            return $this->display(__FILE__, 'views/templates/hook/relatedproducts.tpl');
        }
    }

    protected function getProductsGenerate($id_product)
    {
        // Check if the current product is excluded
        if ($this->isProductExcluded($id_product)) {
            return false; // If it's excluded, return false
        }

        // Primero obtenemos productos relacionados manualmente
        $related_products_manual = $this->getRelatedProductsManual($id_product);

        if (count($related_products_manual) > 0) {
            $filtered_products = array_filter($related_products_manual, function ($related_id) {
                return !$this->isProductExcluded($related_id); // Filter out excluded products
            });
            return $this->getProductsInfo($filtered_products);
        }

        // Si no hay productos manuales, seguimos con la lógica de productos comprados juntos
        $sql = 'SELECT o.id_product 
                FROM ' . _DB_PREFIX_ . 'order_detail o 
                WHERE o.id_order IN (
                    SELECT oo.id_order 
                    FROM ' . _DB_PREFIX_ . 'order_detail oo 
                    WHERE oo.id_product = ' . (int)$id_product . ') 
                AND o.id_product != ' . (int)$id_product . ' 
                GROUP BY o.id_product 
                ORDER BY COUNT(o.product_quantity) DESC 
                LIMIT 3';

        $related_products = Db::getInstance()->executeS($sql);

        if (count($related_products) > 0) {
            $filtered_products = array_filter(array_column($related_products, 'id_product'), function ($related_id) {
                return !$this->isProductExcluded($related_id); // Filter out excluded products
            });
            return $this->getProductsInfo($filtered_products);
        }

        return false;
    }

    protected function getProductsInfo($productIds)
    {
        // Implementa aquí la lógica para obtener la información de los productos
        // según el contexto y tus necesidades.
        $products = [];
        foreach ($productIds as $id) {
            $product = new Product($id, true, $this->context->language->id);
            $products[] = [
                'id' => $product->id,
                'name' => $product->name,
                'price' => Tools::displayPrice($product->getPrice()),
                'link' => $product->getLink(),
                'image' => $product->getCoverWs(),
            ];
        }
        return $products;
    }
}
