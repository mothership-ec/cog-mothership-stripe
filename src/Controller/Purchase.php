<?php

namespace Message\Mothership\Stripe\Controller;

use Message\Cog\Controller\Controller;
use Message\Mothership\Ecommerce\Controller\Gateway\PurchaseControllerInterface;
use Message\Mothership\Commerce\Payable\PayableInterface;
use Message\Cog\HTTP\Response;

/**
 * Controller for purchases using the Stripe server gateway integration.
 *
 * Class Purchase
 * @package Message\Mothership\Stripe\Controller
 * @author Thomas Marchant <thomas@message.co.uk>
 */
class Purchase extends Controller implements PurchaseControllerInterface
{
	// Session keys for purchase() vars
	const PAYABLE_KEY = 'stripe.checkout.payable';
	const STAGES_KEY  = 'stripe.checkout.stages';
	const OPTIONS_KEY = 'stripe.checkout.options';

	public function purchase(PayableInterface $payable, array $stages, array $options = null)
	{
		$this->get('http.session')->set(self::PAYABLE_KEY, $payable);
		$this->get('http.session')->set(self::STAGES_KEY, $stages);
		$this->get('http.session')->set(self::OPTIONS_KEY, $options);

		return $this->redirect($this->generateUrl('ms.ecom.checkout.stripe.card'));
	}

	public function cardDetails()
	{
		$payable = $this->get('http.session')->get(self::PAYABLE_KEY);

		if (!$payable) {
			throw new \UnexpectedValueException('No payable object found in session');
		}

		return $this->render('Message:Mothership:Stripe::card_details', [
			'form'           => $this->createForm($this->get('stripe.checkout.form')),
			'address'        => $payable->getPayableAddress('billing'),
			'publishableKey' => $this->get('gateway.adapter.stripe')->getPublishableKey(),
		]);
	}

	public function purchaseAction()
	{
		list($payable, $stages, $options) = $this->_getSessionVars();

		try {
			$charge = $this->get('gateway.adapter.stripe')->purchase($payable);
		}
		catch (\Stripe_CardError $e) {
			$this->addFlash('error', $e->getMessage());

			$response = $this->forward($stages['failure'], ['payable' => $payable]);
			return $this->redirect($response);
		}

		$response = $this->forward($stages['success'], [
			'payable'   => $payable,
			'reference' => $charge->id,
			'method'    => $this->get('order.payment.methods')->get('stripe'),
		]);

		$content = $response->getContent();
		$data = json_decode($content);
		$url = $data->url;

		return $this->redirect($url);
	}

	protected function _getResponseUrl(Response $response)
	{
		$response = json_decode($response->getContent());

		return $response->url;
	}

	protected function _getSessionVars()
	{
		return [
			$this->get('http.session')->get(self::PAYABLE_KEY),
			$this->get('http.session')->get(self::STAGES_KEY),
			$this->get('http.session')->get(self::OPTIONS_KEY),
		];
	}
}