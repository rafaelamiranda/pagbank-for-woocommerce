<?php
/**
 * Handle webhooks.
 *
 * @package PagBank_WooCommerce\Presentation
 */

namespace PagBank_WooCommerce\Presentation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use WC_Logger;
use WooCommerce;

/**
 * Class WebhookHandler.
 */
class WebhookHandler {

	/**
	 * Instance.
	 *
	 * @var WebhookHandler
	 */
	private static $instance = null;

	/**
	 * Logger.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Initialize webhook handler.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_pagbank_woocommerce_handler', array( $this, 'handle' ) );
		add_action( 'init', array( $this, 'init_logs' ) );
	}

	/**
	 * Initialize logs.
	 */
	public function init_logs() {
		$this->logger = new WC_Logger();
	}

	/**
	 * Get instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get webhook url.
	 */
	public static function get_webhook_url(): string {
		if ( defined( 'PAGBANK_WEBHOOK_SITE_URL' ) && PAGBANK_WEBHOOK_SITE_URL ) {
			return PAGBANK_WEBHOOK_SITE_URL . '/wc-api/pagbank_woocommerce_handler';
		}

		return WooCommerce::instance()->api_request_url( 'pagbank_woocommerce_handler' );
	}

	/**
	 * Log.
	 *
	 * @param string $message Message.
	 */
	private function log( string $message ): void {
		if ( ! $this->logger ) {
			return;
		}

		$this->logger->add( 'pagbank_webhook', $message );
	}

	/**
	 * Handle webhook.
	 */
	public function handle() {
		try {
			$input   = file_get_contents( 'php://input' );
			$headers = getallheaders();

			$content_type = $headers['Content-Type'];

			if ( $content_type !== 'application/json' ) {
				return wp_send_json_error(
					array(
						'message' => 'Content type inválido.',
					),
					400
				);
			}

			$this->log( 'Webhook received: ' . $input );

			$payload = json_decode( $input, true );

			$reference = isset( $payload['reference_id'] ) ? json_decode( $payload['reference_id'], true ) : null;
			$order_id  = isset( $reference['id'] ) ? $reference['id'] : null;
			$signature = isset( $reference['password'] ) ? $reference['password'] : null;

			if ( empty( $order_id ) ) {
				$this->log( 'Webhook validation failed: order_id is empty' );
				return wp_send_json_error(
					array(
						'message' => __( 'Pedido não encontrado.', 'pagbank-for-woocommerce' ),
					),
					400
				);
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$this->log( 'Webhook validation failed: order not found' );
				return wp_send_json_error(
					array(
						'message' => __( 'Pedido não encontrado.', 'pagbank-for-woocommerce' ),
					),
					400
				);
			}

			if ( ! in_array( $order->get_payment_method(), array( 'pagbank_credit_card', 'pagbank_boleto', 'pagbank_pix' ), true ) ) {
				$this->log( 'Webhook validation failed: invalid payment method for order id ' . $order_id );
				return wp_send_json_error(
					array(
						'message' => __( 'Pedido inválido', 'pagbank-for-woocommerce' ),
					),
					400
				);
			}

			$password = $order->get_meta( '_pagbank_password' );
			$charge   = $payload['charges'][0];

			if ( ! $signature ) {
				$this->log( 'Webhook validation failed: missing signature for order id ' . $order_id );
				return wp_send_json_error(
					array(
						'message' => __( 'Assinatura não encontrada.', 'pagbank-for-woocommerce' ),
					),
					400
				);
			}

			$is_valid_signature = $password === $signature;

			if ( ! $is_valid_signature ) {
				$this->log( 'Webhook validation failed: invalid signature for order id ' . $order_id . ' (' . $signature . ')' );
				return wp_send_json_error(
					array(
						'message' => __( 'Assinatura inválida.', 'pagbank-for-woocommerce' ),
					),
					400
				);
			}

			switch ( $charge['status'] ) {
				case 'IN_ANALYSIS':
				case 'WAITING':
					$order->update_status( 'on-hold', __( 'O PagBank está analisando a transação.', 'pagbank-for-woocommerce' ) );

					// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Default order status.
					do_action( 'pagbank_order_on-hold', $order );
					break;
				case 'DECLINED':
					$order->update_status( 'failed', __( 'O pagamento foi recusado.', 'pagbank-for-woocommerce' ) );

					do_action( 'pagbank_order_failed', $order );
					break;
				case 'PAID':
					$order->payment_complete( $charge['id'] );
					$order->update_meta_data( '_pagbank_charge_id', $charge['id'] );
					$order->save_meta_data();

					do_action( 'pagbank_order_completed', $order );
					break;
				case 'CANCELED':
					$order->update_status( 'refunded', __( 'O pagamento foi cancelado.', 'pagbank-for-woocommerce' ) );

					do_action( 'pagbank_order_cancelled', $order );
					break;
			}

			$this->log( 'Webhook processed successfully' );

			return wp_send_json_success(
				array(
					'message' => 'Webhook processed successfully',
				),
				200
			);
		} catch ( Exception $e ) {
			return wp_send_json_error(
				array(
					'message' => 'Erro ao processar o webhook.',
				),
				400
			);
		}

		wp_die();
	}
}
