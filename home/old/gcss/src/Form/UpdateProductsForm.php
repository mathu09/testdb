<?php

namespace Drupal\gcss\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Google_Client;
use Google_Service_ShoppingContent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use \Drupal\file\Entity\File;

/**
 * Class UpdateProductsForm.
 *
 * @package Drupal\gcss\Form
 */
class UpdateProductsForm extends FormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'update_products_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $merchant_accounts = array();

        $merchant_arr = $this->selectMerchantAccounts();
        foreach ($merchant_arr as $merchant) {
            $nid = $merchant->nid;
            $mtitle = $merchant->title;
            $mid = $merchant->field_merchant_id_value;
            $merchant_accounts[$nid] = $mid . ' - ' . $mtitle;
        }
        $form['select_merchant_account'] = [
            '#type' => 'select',
            '#title' => $this->t('Please select merchant account'),
            '#required' => true,
            '#multiple' => false,
            '#options' => $merchant_accounts,
        ];
        $form['update_products'] = array(
            '#type' => 'submit',
            '#value' => $this->t('Update Products'),
        );
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        global $base_url;
        //Public file path : \Drupal::service('file_system')->realpath(file_default_scheme() . "://");
        //Drupal root folder path : DRUPAL_ROOT;
        $service_key_file_path = str_replace('\\', '/', \Drupal::service('file_system')->realpath("private://") . '/service_keys/');
        $form_state_arr[] = $form_state->getValue('select_merchant_account');

        foreach ($form_state_arr as $key => $value) {
            $product_details = $products_arr = $mer_arr = array();
            $account_arr = $this->selectMerchantAccounts($value);
            foreach ($account_arr as $merchant) {
                $nid = $merchant->nid;
                $mid = $merchant->field_merchant_id_value;
                $skey_fid = $merchant->field_service_key_target_id;
                $skey_file = File::load($skey_fid);
                $skey_filename_path = $service_key_file_path . $skey_file->getFilename(); //or maybe $file->filename

                $client = new Google_Client();
                $client->setAccessType('online'); // default: offline
                $client->setIncludeGrantedScopes(true); // incremental auth
                $client->setApplicationName('Content API for Shopping Samples');
                $client->setAuthConfig("$skey_filename_path");
                $client->setScopes(Google_Service_ShoppingContent::CONTENT);

                $service = new Google_Service_ShoppingContent($client);
                try {
                    $products = $service->products->listProducts($mid);
                    $parameters = array();
                    $i = 0;
                    while (!empty($products->getResources())) {
                        foreach ($products->getResources() as $product) {
                            //echo "<pre>"; print_r($product); die;
                            //check the prodcut is already exists or not in drupal
                            $connection = \Drupal::database();
                            $query = $connection->query("select count(field_gproduct_id_value) as cnt from node__field_gproduct_id where field_gproduct_id_value ='" . $product->getId() . "'");
                            $result = $query->fetchAll();
                            if ($result[0]->cnt == 0) {
                                $products_arr[$i]['title'] = $product->getTitle();
                                $products_arr[$i]['body'] = $product->getDescription();
                                $products_arr[$i]['field_gproduct_link'] = $product->getLink();
                                $products_arr[$i]['field_gproduct_gtin'] = $product->getGtin();
                                $products_arr[$i]['field_gproduct_id'] = $product->getId();
                                $products_arr[$i]['field_gproduct_brand'] = $product->getBrand();
                                $products_arr[$i]['field_gproduct_image_link'] = $product->getImageLink();
                                $products_arr[$i]['field_gproduct_price'] = $product->getPrice()->getValue();
                                $products_arr[$i]['field_gproduct_currency'] = $product->getPrice()->getCurrency();
                                $products_arr[$i]['field_gproduct_merchant'] = $nid;
                            }
                            $i++;
                        }
                        if (!empty($products->getNextPageToken())) {
                            break;
                        }
                        $parameters['pageToken'] = $products->nextPageToken;
                        $products = $service->products->listProducts($mid, $parameters);

                    }
                } catch (\Google_Service_Exception $e) {
                    $response = new RedirectResponse('admin/config/gcss/update-products', 302);
                    $response->send();
                }
                $product_details[] = $products_arr;
            }
        }
        //echo "<pre>"; print_r($product_details); die;
        $batch = array(
            'title' => t('Updating products...'),
            'operations' => array(
                array(
                    '\Drupal\gcss\ProductOperations::UpdateProducts',
                    array($product_details),
                ),
            ),
            'finished' => '\Drupal\gcss\ProductOperations::UpdateProductsFinishedCallback',
        );
        batch_set($batch);
    }

    /**
     * {@inheritdoc}
     */
    public function selectMerchantAccounts($nid = '')
    {
        $merchant_accounts = array();
        $connection = \Drupal::database();
        $query = $connection->select('node_field_data', 'n');
        $query->join('node__field_merchant_id', 'mid', 'mid.entity_id = n.nid');
        $query->join('node__field_service_key', 'skey', 'skey.entity_id = n.nid');

        $sessions = $query
            ->fields('n', ['nid', 'title', 'created'])
            ->fields('mid', ['field_merchant_id_value'])
            ->fields('skey', ['field_service_key_target_id'])
            ->condition('n.status', 1)
            ->orderBy('n.created', 'ASC');
        if ($nid != '') {
            $query->condition('n.nid', $nid);
        }
        $merchant_arr = $sessions->execute()->fetchAll();
        return $merchant_arr;

    }

}
