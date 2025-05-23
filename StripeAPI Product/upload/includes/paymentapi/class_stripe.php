<?php

/**
* Class that provides payment verification and form generation functions
*
* @package	vBulletin
* @version	$Revision: 109629 $
* @date		$Date: 2022-06-24 16:53:29 -0700 (Fri, 24 Jun 2022) $
*/

class vB_Exception_Api extends vB_Exception
{
    public function __construct($message = "", $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class vB_PaidSubscriptionMethod_stripe extends vB_PaidSubscriptionMethod
{

	/*
	-- Dev Notes --
	https://stripe.com/docs/development/quickstart/php

	Basic Flow:
	1) Get API keys, set up webhook via API (this is done when the paymentapi options are saved
	  with the webhook)
	2) (Optional) Set up products/subscriptions via API -- we use adhoc pricing, so we skip this.
	3) Generate payment link via API
	4) Customer uses payment link to navigate to Stripe and pay
	5) Stripe sends out a charge.succeeded webhook which we listen to in verify_payment(), and that
	  triggers the provisioning of vBulletin's subscription.
	  Note, bank debits or vouchers may take 2-14 days for confirmation...
		https://stripe.com/docs/payments/checkout/fulfill-orders
	  Another note, Stripe *can* send out an enormous amount of varied webhooks, if registered form them.
	  We only register (listen to) a very select few, see getWebhookEvents() for details.


	You can manage products & prices via Stripe, if you want to manage the product catalog as part of Stripe.
	In this scenario, we could hook up the subscriptions code to automatically add the products & prices whenever
	a subscription is added or modified:
		https://stripe.com/docs/payments/payment-links/api#product-catalog
		https://stripe.com/docs/api/products/create  -- generate a PRODUCT_ID
		https://stripe.com/docs/api/prices/create    -- using PRODUCT_ID above, generate a PRICE_ID for a sub
		https://stripe.com/docs/products-prices/manage-prices
	This would require us to maintain a mapping of subscription.subscriptionid (vB) <=> PRICE_ID (Stripe), and
	supply this PRICE_ID when we create the payment link like in https://stripe.com/docs/payments/payment-links/api#create-link
	This product/price catalog is also editable through the Stripe Dashboard, which might be a desirable feature,
	but would probably also require a lot more support in our code to update our backend appropriately whenever
	a Stripe Webhook related to those changes are invoked.

	Alternatively, we can do "Ad-hoc" pricing: https://stripe.com/docs/products-prices/manage-prices#ad-hoc-prices
	"In some cases, you might want to use a custom price that hasn’t been preconfigured. For example, you might want to allow
	customers to specify a donation amount, or you might manage your product catalog outside of Stripe."
	I believe this is similar to how our PayPal IPN integration works. "Manag[ing] your product catalog outside of Stripe"
	is basically what we want to do (vBulletin's subscription pages in adminCP is essentially the catalog in this case),
	and we don't have to worry about synchronizing data between Stripe & vB and mapping PRICE_IDs. As such, I believe
	this is the method we want to go with -- assuming this doesn't create a huge mess in the Stripe Dashboard, which
	I believe it won't per the aforementioned use-case they support.

	Note that at this time, Payment_Links API does not support ad-hoc pricing:
	"You can only create ad hoc prices through the API. Ad hoc prices aren’t compatible with Payment Links."
	-- https://stripe.com/docs/products-prices/manage-prices#ad-hoc-prices
	and checking the API docs, payment_links API ( https://stripe.com/docs/api/payment_links/payment_links/create#create_payment_link-line_items )
	does not have the ad-hoc "price_data" that the checkout API ( https://stripe.com/docs/api/checkout/sessions/create#create_checkout_session-line_items-price_data )
	has.


	--- Testing ---
	CC's, bank #s for testing:
		https://stripe.com/docs/testing#cards
		https://stripe.com/docs/connect/testing#payouts

	(Note, remove "test" from URL for LIVE MODE dashboard URLs)

	Monitor and update webhooks:
		https://dashboard.stripe.com/test/webhooks
		You can also grab a webhook's ID & webhook secret by clicking on the individual webhook on this page

	Monitor events/webhooks:
		https://dashboard.stripe.com/test/events
		Note that events with associated (listening) webhooks are indicated with a special circular triforce icon.

	View / refund/cancel payments & subscriptions:
		https://dashboard.stripe.com/test/payments
		https://dashboard.stripe.com/test/subscriptions

	Search metadata:
		https://dashboard.stripe.com/test/search?query=source%3AvBulletin


	--- Some General Set up Notes ---

	Sign up at stripe.com
	Verify your email
	Activate Payment (AFAIK, this step is required before being able to switch from TEST mode to LIVE mode)

	API keys can be found at
		LIVE: https://dashboard.stripe.com/apikeys
		TESTMODE: https://dashboard.stripe.com/test/apikeys
	Important note, check whether you're viewing the test or live api keys. At the top, there should be a banner saying
	"Vewing test/live API keys. Toggle to view live/test keys." and a toggle button. Use the toggle button to switch
	freely between the modes.
	Another important note, the secret key for LIVE MODE only shows up ONCE!
	-- https://stripe.com/docs/development/dashboard/manage-api-keys#reveal-an-api-secret-key-for-live-mode-(one-time)
	If you lose it, you can roll the API key but this will cause any other application using the old API key to fail.

	Setting up Automatic Tax on Stripe: https://stripe.com/docs/tax/set-up
	Note that Stripe charges a fee for this feature: https://stripe.com/docs/tax/faq#pricing

	Speaking of pricing, Stripe is NOT FREE: https://stripe.com/pricing
	At the time of writing, the fees per charge/transaction are below:
	* 2.9% + 30c for cards & wallets,
	* 0.8% ($5 cap) for bank debits & transfers
	* Starting at 80c for "additional payment methods"

	Note that the Checkout API we use comes with a prebuilt, Stripe-hosted payment page, and that is and that is already
	included in the above fees (unless you want the payment page on a custom domain which is an extra $10 per month).

	As far as the customer's concerned, the payment flow goes like this:
	- Click the button on vB subscription signup page
	- This pops up a 3rd-party payments page (similar to PayPal) which they need to fill out and submit
	vBulletin will later (in testing, usually in a few seconds) receive a webhook from Stripe, then provision out the subscription.

	*/


	private $initialized = NULL;
	private $init_fail_reason = '';
	private $debug = true;
	private $enable_telemetry = false;
	// Used for storing the message body for logging errors.
	private $payload;

	/**
	 * Log file for debugging
	 */
	private $logfile = __DIR__ . '/stripe.log';

	/**
	 * Avoid backwards-imcompatible changes, as the account default may not necessarily line up with
	 * when we last made changes to this class. See https://stripe.com/docs/api/versioning
	 *
	 * @var string
	 */
	private $stripe_api_version = '2020-08-27';

	/**
	 * Variables to store payment information
	 */
	var $session_id;
	var $is_webhook_request;
	var $metadata;
	var $payment_status;
	var $paid_amount;
	var $currency;

	private function initStripeSDK()
	{
		if (!is_null($this->initialized))
		{
			return $this->initialized;
		}

		/*
		Stripe SDK requires curl, json & mbstring.
		*/
		$dependencies = [
			'curl',
			'json',
			'mbstring',
		];
		foreach ($dependencies AS $__dep)
		{
			if (!extension_loaded($__dep))
			{
				$this->init_fail_reason = 'Dependency missing: ' . $__dep;
				$this->log($this->init_fail_reason);
				$this->handleException(new Exception($this->init_fail_reason));
				$this->initialized = false;
				return false;
			}
		}

		try
		{
			require_once(DIR . '/includes/libraries/stripe/init.php');
		}
		catch (Exception $e)
		{
			$this->init_fail_reason = 'Stripe SDK failed to initialize';
			$this->log($this->init_fail_reason);
			$this->log($e->getMessage());
			$this->handleException($e);
			$this->initialized = false;
			return false;
		}

		// Let's allow some custom configurations -- may be useful on specific servers.
		// Warning: These are untested.
		$config = $this->registry->config;
		if (!empty($config['Misc']['paymentapi']['stripe']))
		{
			$opts = $config['Misc']['paymentapi']['stripe'];

			if (!empty($opts['custom_ca_certs']))
			{
				// https://github.com/stripe/stripe-php#configuring-ca-bundles
				// Allow configuring CA certs. Default is currently in libraries/stripe/data/ca-certificates.crt
				\Stripe\Stripe::setCABundlePath($opts['custom_ca_certs']);
			}

			if (!empty($opts['retries']))
			{
				\Stripe\Stripe::setMaxNetworkRetries($opts['retries']);
			}

			if (!empty($opts['CURLOPT_SSLVERSION']))
			{
				// Note, 'CURLOPT_SSLVERSION' is intentionally a string, not the constant. If we want to use
				// any consts, we should wrap each one in its own array like ['stripe']['curl'][CURLOPT_SSLVERSION]
				// to avoid different constant ints possibly stepping over each other.
				// e.g. $config['Misc']['paymentapi']['stripe']['CURLOPT_SSLVERSION'] => CURL_SSLVERSION_TLSv1_2

				// Older systems might default to TLS 1.0 by default even while supporting TLS 1.2.
				// If, for some reason, they cannot upgrade their system to change the default, the following *MAY*
				// resolve the issue, per https://github.com/stripe/stripe-php#ssl--tls-compatibility-issues
				$curl = new \Stripe\HttpClient\CurlClient([CURLOPT_SSLVERSION => $opts['CURLOPT_SSLVERSION']]);
				\Stripe\ApiRequestor::setHttpClient($curl);
			}

			if (isset($opts['enable_telemetry']))
			{
				$this->enable_telemetry = $opts['enable_telemetry'];
			}
		}

		// https://github.com/stripe/stripe-php#request-latency-telemetry
		// Enable/disable Stripe telemetry.
		// Per feedback, we'll default to telemetry disabled unless explicitly enabled via the config above.
		\Stripe\Stripe::setEnableTelemetry($this->enable_telemetry);


		$this->initialized = true;
		return true;
	}

	/**
	 * Handles stripe SDK exceptions (expose the exception if in debug mode)
	 *
	 * @param	object	The exception
	 */
	private function handleException(Throwable $e)
	{
		{

			$this->error = "{$e->getMessage()}";

			file_put_contents($this->logfile, date('[Y-m-d H:i:s]') . " {$this->error_code}: {$this->error}" . PHP_EOL, FILE_APPEND);
			throw $e;
		}
	}

	private function log($msg)
	{
		if (!$this->debug)
		{
			return;
		}

		$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		// item 0 will be the log() call, so it has the right line but
		// we need to know the function that called log, which is one stack above.
		$info = '';
		if (!empty($bt[1]['class']))
		{
			$info = $bt[1]['class'] . "->" . $bt[1]['function'] . "() @ LINE " . $bt[0]['line'];
		}
		else
		{
			$info = $bt[0]['file'] . " @ LINE " . $bt[0]['line'];
		}
		if (!is_string($msg))
		{
			$msg = print_r($msg, true);
		}
		error_log(
			$info
			. "\n$msg"
		);
	}

	/**
	 * Constructor
	 *
	 * @param   array  `paymentapi` record associated with this payment method.
	 *
	 */
	function __construct($paymentapirecord)
	{
		parent::__construct($paymentapirecord);

		$config = $this->registry->config;
		// Note: Logging will only happen when config.Misc.debugpayments is set.
		$this->debug = !empty($config['Misc']['debugpayments']) && $config['Misc']['debugpayments'] === true;

		// Set payment info.
		$this->registry->input->clean_array_gpc('r', array(
			'session_id'    => TYPE_STR,
			'is_webhook_request' => TYPE_BOOL,
		));

		$this->is_webhook_request = intval($this->registry->GPC['is_webhook_request']);
		$this->session_id = $this->registry->GPC['session_id'];
		$this->display_feedback = $this->is_webhook_request !== 1;

		// Initialize the Stripe SDK.
		$this->initStripeSDK();
	}

	/**
	 * Fetch Stripe's subscriptionid from a Stripe invoice (only exists for recurring charges)
	 *
	 * @param  \Stripe\Invoice  $invoice
	 *
	 * @return string
	 */
	private function fetchStripeSubscriptionID($invoice)
	{
		if (is_null($invoice) OR !isset($invoice->subscription))
		{
			// An invoice (& its subscription) only exists for a recurring payment subscription.
			// If this was a one-time payment, we have no Stripe subscription we need to cancel later.
			return '';
		}

		return $invoice->subscription;
	}

	/**
	 * Fetch payment amount & tax (in smallest denomination) from the event data or invoice
	 * depending on the type of event & tax behavior.
	 *
	 * @param  \Stripe\Event          $event
	 * @param  null|\Stripe\Invoice  $invoice  null if one-time payment instead of recurring.
	 *
	 * @return array[
	 *  'amount'    => int,
	 *  'currency'  => string,
	 *  'tax'       => int,
	 *  'inclusive' => bool,
	 * ]
	 */
	private function fetchAmountInfoInCents($event, $invoice)
	{
		$currency = 'usd';
		$amount = 0;
		if (isset($event->data->object->currency))
		{
			$currency =  strtolower($event->data->object->currency);
			// We may want to check & track amount_captured if we want to support partial captures...
			// Warning: "Charge" objects do not have a concept of taxes. This amount is the total, post-tax
			// amount (inclusive OR exclusive).
			$amount = $event->data->object->amount;
		}

		$default = [
			'amount' => $amount,
			'currency' => $currency,
			'tax' => 0,
			'inclusive' => true,
		];

		// Per Stripe dev support, the charge object does not know about taxes.
		// We must fetch it from the related invoice or checkout session accordingly.
		if (empty($invoice))
		{
			// If, for some reason, we don't have a payment_intent, there's nothing we can
			// do. Just return the default amounts. This *might* happen if this webhook was
			// actually not a charge-associated event.
			if (empty($event->data->object->payment_intent))
			{
				return $default;
			}
			$payment_intent = $event->data->object->payment_intent;

			// The checkout session may be fetched via using the linked payment_intent id &
			// listing all checkout sessions via that payment_intent. Note that even though
			// list (list == all() in the PHP SDK) returns a collection, a single payment
			// intent is only associated with a single session (so list size is always 1
			// when payment_intent is specified).
			try
			{
				$params = ['payment_intent' => $payment_intent, ];
				$checkoutSessions = $this->getStripeClient()->checkout->sessions->all($params);
				if (empty($checkoutSessions->data))
				{
					return $default;
				}
				/**
				 * @var \Stripe\Checkout\Session
				 */
				$checkoutSession = $checkoutSessions->data[0];
				// https://stripe.com/docs/api/checkout/sessions/object?lang=php
				// amount_total: WITH taxes & discounts.
				// amount_subtotal: BEFORE taxes & discounts. This is the value that'll match the vb subscription price
				// for both inclusive & exclusive tax types.
				// If there are no discounts, the total amount & subtotal amount are the same in inclusive mode, while
				// the total amount = subtotal + tax in exclusive mode.
				$taxIsInclusive = ($checkoutSession->amount_total == $checkoutSession->amount_subtotal);
				$currency = strtolower($checkoutSession->currency);
				$amount = $checkoutSession->amount_subtotal;
				$tax = $checkoutSession->total_details->amount_tax;
				return [
					'amount' => $amount,
					'currency' => $currency,
					'tax' => $tax,
					'inclusive' => $taxIsInclusive,
				];
			}
			catch (Throwable $e)
			{
				return $default;
			}
		}
		else
		{
			// https://stripe.com/docs/api/invoices/object
			$currency = strtolower($invoice->currency);
			// Note, we will not be handling partial payments of invoices at this time, and always assume that
			// the full amount is paid.
			$amount = $invoice->amount_paid;
			$taxIsInclusive = ($invoice->subtotal == $amount);
			$tax = $invoice->tax;
			// This used to be if (!$taxIsInclusive) $amount = $amount - $tax; but this subtraction was triggering
			// all kinds of weird floating point precision issues that didn't trivially resolve via doing money
			// comparisons by converting them back to cents (INTs) first, because the converted INT value was
			// somehow off by 1 cent.
			// I'm taking off all of the FromCents conversions here, and also just avoid subtraction altogether
			// by using another field that should already be the value we're looking for for exclusive tax mode.
			if (!$taxIsInclusive)
			{
				$amount = $invoice->total_excluding_tax;
			}
			return [
				'amount' => $amount,
				'currency' => $currency,
				'tax' => $tax,
				'inclusive' => $taxIsInclusive,
			];
		}
	}

	/**
	 * Fetch vb-subscription-metadata from the event data or Stripe
	 * depending on the type of event.
	 *
	 * @param  \Stripe\Event  $event
	 * @param  null           $invoice  an OUT var -- this will be a \Stripe\Invoice object if $event is for
	 *                        a recurring subscription and not a onetime payment.
	 *
	 * @return array|bool  metadata array or false on failure
	 */
	private function fetchMetaDataAndSetHeaders($event, &$invoice)
	{
		/**
		 * @var \Stripe\Charge
		 */
		$stripeObject = $event->data->object;

		// If the event data object is a refund, we need to fetch the charge object from it.
		if ($stripeObject instanceof \Stripe\Refund) {
			$stripeObject = $stripeObject->charge;

			// If the charge is not an object but a string ID, we need to fetch it from Stripe.
			if (!($stripeObject instanceof \Stripe\Charge) && is_string($stripeObject)) {
				try {
					$stripeObject = $this->getStripeClient()->charges->retrieve($stripeObject);
				} catch (Throwable $e) {
					$this->indicateRetry();
					$this->error_code = 'failed_fetch_charge';
					$this->error = 'Failed to fetch Stripe charge.';
					return false;
				}
			}
		}

		$charge = $stripeObject;

		// We'll need the invoice for additional processing later, so always fetch it.
		if (!empty($charge->invoice))
		{
			try
			{
				$invoice = $this->getStripeClient()->invoices->retrieve($charge->invoice);
			}
			catch (Throwable $e)
			{
				$this->indicateRetry();
				$this->error_code = 'failed_fetch_invoice';
				return false;
			}
		}
		// debugging
		//$this->log("Charge: " . print_r($charge, true));
		//$this->log("Invoice: " . print_r($invoice, true));

		if (!empty($charge->metadata['hash']))
		{
			$this->indicateOk();
			return $charge->metadata;
		}
		else if (!empty($invoice))
		{
			if (!empty($invoice->lines->data[0]->metadata['hash']))
			{
				$this->indicateOk();
				return $invoice->lines->data[0]->metadata;
			}
			else
			{
				// Something unexpected happens, and the places we were relying on to store our paymentinfo hash is not
				// giving us the necessary info... fail and log it, but send 200 header since getting this webhook again
				// is not going to help us.
				$this->indicateOk();
				$this->error_code = 'metadata_missing';
				return false;
			}
		}

		// shouldn't get here, but just setting up unhandled error in case of sloppy code changes here.
		$this->indicateOk();
		$this->error_code = 'metadata_missing';
		$this->error = 'Metadata missing from Stripe charge & invoice.';
		return false;
	}

	/**
	 * Only call this or indicateRetry() once per process.
	 */
	private function indicateOk()
	{
		if (!headers_sent())
		{
			http_response_code(200);
			// Stripe (& other payment processors in general) require that you return a header status as fast as possible, before
			// running any processing that might cause a timeout on their end, otherwise they may re-send the event.
			// As such, unless we're explicitly waiting for output, we should send any headers back before we do longer internal
			// processing
			flush();
		}
	}

	/**
	 * Only call this or indicateOk() once per process.
	 */
	private function indicateRetry()
	{
		if (!headers_sent())
		{
			// Stripe will resend us the event if we send a non 200 status. As such, this should only
			// be used when we want stripe to resend the event due to malformed data, etc, NOT for
			// something we should internally reject.
			http_response_code(400);
			flush();
		}
	}

	/**
	 * Called by payment_gateway.php for logging transaction for TXN_TYPE_ERROR & TXN_TYPE_LOGONLY.
	 *
	 * @return array
	 */
	public function getRequestForLogging()
	{
		$data = [
			'JSON'          => $this->payload,
		];
		return $data;
	}

	/**
	* Perform verification of the payment, this is called from the payment gateway
	*
	* @return	bool	Whether the payment is valid
	*/
	function verify_payment()
	{
		try {
			// If the request is not a webhook request nor a payment redirect with a session ID, return false with an error.
			if ($this->is_webhook_request != 1 && empty($this->session_id)) {
				$this->error_code = 'invalid_request';
				$this->error = 'Invalid Request.';

				http_response_code(400);
				return false;
			}

			// Process the Stripe Webhook request.
			if ($this->is_webhook_request == 1 && !$this->processWebhookRequest()) {
				return false;
			}

			//  Process the Stripe Payment redirection.
			if (!empty($this->session_id) && !$this->processRedirectionRequest()) {
				return false;
			}

			$this->paymentinfo = $this->registry->db->query_first("
				SELECT paymentinfo.*, user.username
				FROM " . TABLE_PREFIX . "paymentinfo AS paymentinfo
				INNER JOIN " . TABLE_PREFIX . "user AS user USING (userid)
				WHERE hash = '{$this->registry->db->escape_string($this->metadata['hash'])}'
			");

			if (empty($this->paymentinfo)) {
				$this->error_code = 'payment_not_found';
				$this->error = 'Payment not found';

				return false;
			}

			$this->paymentinfo['amount'] = $this->paid_amount;
			$this->paymentinfo['currency'] = $this->currency;

			switch ($this->payment_status) {
				case 'paid':
					$sub = $this->registry->db->query_first("SELECT * FROM " . TABLE_PREFIX . "subscription WHERE subscriptionid = {$this->paymentinfo['subscriptionid']}");
					$costs = vb_unserialize($sub['cost']);
					$cost = $costs[$this->paymentinfo['subscriptionsubid']] ?? null;

					if (!empty($cost) && $this->paid_amount == doubleval($cost['cost'][$this->currency])) {
						$this->type = 1;
					} else {
						$this->error_code = 'invalid_payment_amount';
						$this->error = 'Payment amount does not match subscription cost.';
					}

					break;
				case 'refunded':
					$this->type = 2;
					break;
				case 'refund_failed':
					$this->type = 3;
					break;
				default:
					$this->error_code = 'invalid_payment_status';
					$this->error = 'Invalid payment status.';

					return false;
			}

			return $this->type > 0;
		} catch (Exception $e) {
			$this->error_code = 'stripe_verify_payment_failed';
			$this->error = "Stripe payment verification failed: {$e->getMessage()}";

			file_put_contents($this->logfile, date('[Y-m-d H:i:s]') . " {$this->error_code}: {$this->error}" . PHP_EOL, FILE_APPEND);

			return false;
		}
	}

	/**
	 * Process the Stripe webhook request.
	 *
	 * @return	bool
	 */
	public function processWebhookRequest(): bool
	{
		// We are expecting application/JSON data, which won't be available in $_POST.
		// Note, this is set before the SDK init so the message is logged in paymenttransaction for debugging purposes even if our end fails.
		// Do not decode this data, as Stripe SDK will parse it on its own.
		$this->payload = file_get_contents('php://input');
		$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];

		if (!$this->initialized)
		{
			$this->indicateRetry();
			$this->error_code = 'stripe_sdk_failure';
			$this->error = 'Stripe SDK failed to initialize.';

			return false;
		}

		$event = $this->verifyWebhookMessage($this->payload, $signature);
		if (!$event)
		{
			$this->indicateRetry();
			//$this->log("Payload:" . print_r($payload, true));
			//$this->log("signature:" . print_r($signature, true));
			// $this->type = self::TXN_TYPE_ERROR;
			return false;
		}

		// If we got past the verify, we need to let Stripe know so they don't try to resend this,
		// regardless of what our handling below turns out to be.
		// Edit: we abuse this to retrigger in case our fetch-invoice fails in fetchMetaDataAndSetHeaders(),
		// (assuming due to network issues) because we don't have internal retries. Otherwise we would want
		// to log this transaction, keep track of processing state, then have a cron pick it up and retry
		//http_response_code(200);

		//$this->log($event);

		// fetchMetaDataAndSetHeaders() might request retries if the event doesn't have some data we expect,
		// so ignore events that we're not explicitly aware of as to avoid any issues (this usually only happens
		// when the webhook is manually created to listen to extra events, or when using local forwarding via
		// Stripe CLI)
		if (!in_array($event->type, $this->getWebhookEvents()))
		{
			$this->indicateOk();
			$this->log("Received unhandled Stripe event: " . print_r($event, true));
			// If it's not something we explicitly handle, let's just log it (not a failure) and return.
			$this->type = self::TXN_TYPE_LOGONLY;
			return false;
		}


		// fetchMetaDataAndSetHeaders() sets the appropriate HTTP status, and we shouldn't concern
		// ourselves with it after this point.
		$invoice = null;
		$this->metadata = $this->fetchMetaDataAndSetHeaders($event, $invoice);

		if (empty($this->metadata)) {
			// $this->type = self::TXN_TYPE_ERROR;
			$this->error_code = 'metadata_missing';
			$this->error = 'Metadata missing from Stripe charge & invoice.';
			return false;
		}

		// The data object is either a charge or a refund.
		$this->transaction_id = $event->data->object instanceof \Stripe\Charge ? $event->data->object->id : $event->data->object->charge;
		// Using the hash from the metadata, find the payment info
		// paymentinfo is set in the verify_payment() method.
		// if (!$this->getSetPaymentInfo($this->metadata)) {
		// 	$this->type = self::TXN_TYPE_ERROR;
		// 	return false;
		// }

		/*
		Depending on the checkout session mode (we currently use only payment|subscription)
		Different webhook events may be fired.
		In general:
		* checkout.session.completed -- After customer finishes their payment flow on Stripe.
		** May have $event->data->object->payment_status == 'paid' if paid, or 'unpaid' if delayed
			In this case, we should fulfill if status == 'paid', otherwise wait for below
		* checkout.session.async_payment_succeeded -- if the delayed payment above finished.
			This can be treated as status == 'paid' above, and we can fulfill the delayed order.
		* checkout.session.async_payment_failed -- if the delayed payment failed for some reason.
			In this case, we should postpone the fulfillment, and perhaps alert the customer to retry
			payment.

		For subscriptions, the first payment will have the above checkout.session.XYZ webhooks, but
		will also have
		* invoice.paid -- First time AND each renewal will send this upon successful payments.
		or
		* invoice.payment_failed -- Payment failed.

		Stripe docs recommend provisioning the subscription upon checkout.session.completed, then
		renew on each invoice.paid. However, in testing, the first payment invokes invoice.paid.
		This means that if we go with the suggested approach, we need ot filter out or distinguish
		a first time invoice.paid as to avoid provisioning the subscription twice for the initial
		purchase. As such, it seems much simpler to just listen to the invoice.paid for subscription
		cases...
		Another problem lies in how to hook up the event to the paymentinfo record.
		Different events carry different portions (or none at all) of the data we first pass into Stripe,
		which makes it annoying to have a universal logic.


		Sources:
			https://stripe.com/docs/payments/checkout/fulfill-orders#fulfill
			https://stripe.com/docs/billing/subscriptions/build-subscriptions?ui=checkout#provision-and-monitor


		 */

		[
			'amount' => $amount_cents,
			'currency' => $currency,
		] = $this->fetchAmountInfoInCents($event, $invoice);

		// This event *should* have the payment info (and they did in all of my testing)
		// but just being overly cautious about identifying failure modes
		if (is_null($amount_cents) || is_null($currency)) {
			$this->type = self::TXN_TYPE_ERROR;
			$this->error_code = 'missing_currency_amount_data';
			$this->error = 'Missing currency or amount data in the webhook event.';
			return false;
		}

		// set the currency & cost from what was posted to us. From what I can tell, this is only
		// used by the payment_gateway.php for 1) `paymenttransaction` logging and 2) payment/refund
		// email notices to settings.paymentemail
		$this->currency = $currency;
		$this->paid_amount = $this->convertFromCents($amount_cents, $currency);
		// $assertor = vB::getDbAssertor();

		switch ($event->type)
		{
			case 'charge.succeeded':
				$this->payment_status = 'paid';
				return true;
			case 'charge.refunded':
				$this->payment_status = 'refunded';
				return true;
			case 'refund.failed':
				$this->payment_status = 'refund_failed';
				return true;
			default:
				// This should've already been caught above in the getWebhookEvents() check, but just leaving a default handler.
				$this->log("Received unhandled Stripe event: " . print_r($event, true));
				// If it's not something we explicitly handle, let's just log it (not a failure) and return.
				// $this->type = self::TXN_TYPE_LOGONLY;
				return false;
		}
	}

	function processRedirectionRequest(): bool
	{
		$stripeSession = $this->getStripeClient()->checkout->sessions->retrieve($this->session_id);

		if (empty($stripeSession)) {
			$this->error_code = 'invalid_stripe_session_id';
			$this->error = 'Invalid Stripe session ID.';

			return false;
		}

		$this->metadata = $stripeSession->metadata;
		$this->transaction_id = $stripeSession->payment_intent;
		$this->payment_status = $stripeSession->payment_status;
		$this->paid_amount = doubleval($stripeSession->amount_total / 100);
		$this->currency = $stripeSession->currency;

		return true;
	}

	/**
	 * For APIs that support it, call the remote endpoint to cancel automatic recurring payments when
	 * a user cancels their vBulletin recurring subscription.
	 *
	 * @param int   $userid
	 * @param int   $vbsubscriptionid
	 */
	public function cancelRemoteSubscription($userid, $vbsubscriptionid)
	{
		$params = [
			'paymentapiid' => $this->paymentapirecord['paymentapiid'],
			'vbsubscriptionid' => $vbsubscriptionid,
			'userid' => $userid,
			'active' => 1,
		];
		$assertor = vB::getDbAssertor();
		try
		{
			$stripeSubs = $this->getStripeClient()->subscriptions;

			$subids = $assertor->getColumn('vBForum:paymentapi_subscription', 'paymentsubid', $params);
			$defaultParams = $params;
			foreach ($subids AS $__subid)
			{
				$__conditions = $defaultParams;
				$__conditions['paymentsubid'] = $__subid;

				/*
				// If we want to cancel at the end of the duration customer already paid for instead of immediately, for some reason
				// https://stripe.com/docs/billing/subscriptions/cancel#cancel-at-end-of-cycle
				$stripeSubs->update($__subid, [
					'cancel_at_period_end' => true,
				]);
				*/
				$__response = $stripeSubs->cancel($__subid);
				// debugging
				//$this->log("Cancellation response: " . print_r($__response, true));
				if ($__response->status == 'canceled')
				{
					$assertor->update('vBForum:paymentapi_subscription', ['active' => 0], $__conditions);
				}
			}

			return true;
		}
		catch (Throwable $e)
		{
			$this->log($e);
			//$this->handleException($e);
			// fail quietly as to not block any other paymentAPI attempts...
			return false;
		}
	}

	private function getSetPaymentInfo($metadata)
	{
		$assertor = vB::getDbAssertor();
		$this->paymentinfo = $assertor->getRow('vBForum:getPaymentinfo', ['hash' => $metadata['hash']]);

		// lets check the values
		if (!empty($this->paymentinfo))
		{
			return true;
		}
		else
		{
			$this->error_code = 'invalid_subscriptionid';
			return false;
		}
	}

	/**
	 * Verify Webhook Message signature from Stripe, and return the Event object from Stripe
	 *
	 * @param string $payload      JSON (undecoded) post data
	 * @param string $signature    HTTP_STRIPE_SIGNATURE header
	 *
	 * @return \Stripe\Event | false  Stripe event on message verification success, false on failure.
	 */
	private function verifyWebhookMessage($payload, $signature)
	{
		try {
			// https://stripe.com/docs/webhooks/signatures
			// Doesn't seem like there's a non-static way to get the event out...
			\Stripe\Stripe::setApiKey($this->getPrivateKey());

			return \Stripe\Webhook::constructEvent(
				$payload,
				$signature,
				$this->getWebhookSecret()
			);
		}
		catch(\UnexpectedValueException $e)
		{
			// Invalid payload
			$this->error_code = 'invalid_payload';
			$this->error = 'Invalid Stripe webhook payload.';
			$this->log($e);
			//http_response_code(400);
			return false;
		}
		catch(\Stripe\Exception\SignatureVerificationException $e)
		{
			// Invalid signature
			$this->error_code = 'invalid_signature';
			$this->error = 'Invalid Stripe webhook signature.';
			$this->log($e);
			//http_response_code(400);
			return false;
		}
		catch (Throwable $e)
		{
			// Let's catch everything generic as well
			$this->error_code = 'verify_webhook_unknown_error';
			$this->error = 'Unknown error verifying Stripe webhook.';
			$this->log($e);
			//http_response_code(400);
			return false;
		}
	}

	// These methods were originally set up when the settings were being set externally,
	// and we had no guarantee that the setting actually existed. This should no longer
	// be the case, unless the API record passed into the constructor was invalid.
	private function getPublicKey()
	{
		return $this->settings['public_key'] ?? '';
	}

	private function getPrivateKey()
	{
		return $this->settings['secret_key'] ?? '';
	}

	private function getWebhookID()
	{
		return $this->settings['webhook_id'] ?? '';
	}

	private function getWebhookSecret()
	{
		return $this->settings['webhook_secret'] ?? '';
	}

	private function getStripeClient(): \Stripe\StripeClient
	{
		$stripe = new \Stripe\StripeClient([
			'api_key' => $this->getPrivateKey(),
			// Let's force the version just in case there's some breaking changes later.
			// https://stripe.com/docs/videos/developer-foundations?video=versioning
			// This version was taken from the following doc at the time this class was
			// written & PHP SDK was downloaded: https://stripe.com/docs/api/versioning
			'stripe_version' => $this->stripe_api_version,
		]);

		// Note, we can set Network retries like \Stripe\Stripe::setMaxNetworkRetries(2);
		// In that case, idempotency keys are automatically added to the request to ensure
		// retries are safe:
		// https://github.com/stripe/stripe-php/blob/8275e7a8ee4aa8890baf581bde12a1fe5b201ebd/lib/HttpClient/CurlClient.php#L236


		return $stripe;
	}

	/**
	* Test that required settings are available, and if we can communicate with the server (if required)
	*
	* @return	bool	If the vBulletin has all the information required to accept payments
	*/
	public function test()
	{
		if (!$this->initialized)
		{
			$this->log("Stripe test failed because Stripe SDK failed to initialize. See previously logged errors.");
			return false;
		}

		$this->log('SDK initialized');

		if (
			empty($this->getPrivateKey()) OR
			empty($this->getPublicKey()) OR
			empty($this->getWebhookID()) OR
			empty($this->getWebhookSecret())
		)
		{
			$this->log("Stripe test failed because at least one required setting (Secret Key, Publishable Key, Webhook ID, or Webhook Signing Secret) was missing. \n" .
				"Check the Payment API manager for Stripe to view these settings. \n" .
				"If Webhook ID and Webhook Signing Secret are missing, verify the Secret Key and try re-saving the Payment API settings to automatically generate them."
			);
			return false;
		}


		// Stripe API requires TLS 1.2 and will reject requests using older TLS versions
		// https://github.com/stripe/stripe-php/#ssl--tls-compatibility-issues
		// https://support.stripe.com/questions/upgrade-your-php-integration-from-tls-1-0-to-tls-1-2
		try
		{
			\Stripe\Stripe::setApiKey($this->getPrivateKey());
			\Stripe\Charge::all();
			$this->log("Stripe connection test -- TLS 1.2 supported");
		}
		catch (Throwable $e)
		{
			// May be due to TLS... but also may be something else entirely (e.g. invalid CA certs). Can try checking curl https://www.howsmyssl.com/a/check , but the
			// exception probably will contain the most useful info
			$this->log("Stripe connection test failed. Check the following logged exception for more details.");
			$this->log($e);
			$this->handleException($e);
			return false;
		}

		// I'm not sure what a good "test" call would be, as there isn't a test endpoint AFAIK.
		// Listing all webhook endpoints and just seeing if that does not throw errors seems as
		// good as any.
		// For an invalid API key, for example, that will usually throw a Stripe\Exception\AuthenticationException
		// https://stripe.com/docs/error-handling
		try
		{
			$endpoints = $this->getStripeClient()->webhookEndpoints->all();
			if (!($endpoints instanceof Stripe\Collection))
			{
				$this->log("Unexpected return from Stripe. Expected Stripe\\Collection, but got: ". print_r($endpoints, true));
				return false;
			}

			$existingWebhook = $this->getStripeClient()->webhookEndpoints->retrieve($this->getWebhookID());
			$listenerurl = $this->getOurWebhookEndpoint();
			if ($existingWebhook->url != $listenerurl)
			{
				$this->log("Webhook (ID: {$existingWebhook->id}) did not have the expected URL. Expected: $listenerurl , Got: {$existingWebhook->url}");
				return false;
			}
			else
			{
				$this->log("Stripe webhook found: " . print_r($existingWebhook, true));
			}
		}
		catch (Throwable $e)
		{
			$this->log($e);
			$this->handleException($e);
			return false;
		}


		return true;
	}

	private function getOurWebhookEndpoint()
	{
		$listenerurl = $this->registry->options['bburl'] . '/payments.php';
		return $listenerurl;
	}

	private function getWebhookEvents()
	{
		/*
		This is in a separate function to help document some reasonsings.
		These are the events that we COULD listen to:
			// --- Checkout session related (Stripe-side first time payment form submission) ---
			// https://stripe.com/docs/payments/checkout/fulfill-orders#fulfill
			'checkout.session.completed',
			'checkout.session.async_payment_succeeded',
			'checkout.session.async_payment_failed',
			// --- onetime payments ---
			'charge.succeeded',
			'charge.failed',
			'charge.refunded',
			//  --- subscriptions --- https://stripe.com/docs/billing/subscriptions/build-subscriptions?ui=checkout#provision-and-monitor
			// Sent each billing interval when a payment succeeds.
			'invoice.paid',
			// Sent each billing interval if there is an issue with your customer’s payment method.
			'invoice.payment_failed',

		Per testing:
		vBulletin has 3 distinct payment events we need to pay attention to: Onetime payment, recurring subscription signup, recurring subscription renewal.
		Onetime payments will invoke a checkout.session.completed & charge.succeeded on successful payment.
		Recurring subscription's first time payment will invoke a checkout.session.completed, charge.succeeded & invoice.paid on succesful payment.
		Renewal payments will allegedly invoke a invoice.paid & thus likely a charge.succeeded payment (TODO update this after renewal event occurs).

		In order for us to connect the incoming webhook to the `paymentinfo` record, we must pass the `paymentinfo`.`hash` into Stripe and get it
		back in the webhook somehow. This would usually be done via the checkout's "client_reference_id" or various "metadata" fields, but depending
		on the particular trigger and event, these fields are not available.

		For example, for one-time payment:
			checkout.session.completed -- contains the session::create()'s client_reference_id & metadata
			charge.succeeded -- does NOT contain the above, but does contain the payment_intent_data.metadata
		For first time payment of a recurring sub:
			checkout.session.completed -- contains the session::create()'s client_reference_id & metadata
			charge.succeeded -- does NOT contain the above, NOR the payment_intent_data.metadata (because payment_intent_data cannot be set for recurring).
						-- also contains an "invoice" (invoice ID) field, which can be used to look up the associated invoice... see below.
			invoice.paid -- does not contain above, but does contain the subscription_data.metadata
		per above, a renewal would then trigger
			charge.succeeded
			invoice.paid
		with probably similar data retention.
		The invoice data in the webhook do not contain metadata from product_data, but if we fetch the full invoice info from Stripe, that contains a
		"lines" field which does contain product_data.metadata.

		So if we only listen to checkout.session.completed, we'll miss renewals.
		If we only listen to invoice.paid, we'll miss non-recurring payments.
		If we only listen to charge.succeeded, presumably we'll be notified in all cases, but the metadata may not be available.

		To summarize:
		* There is no singular point where we can set the metadata and get it directly in all 3 payment cases
		* Only charge.succeeded is invoked for all 3 payment cases. Other events conditionally overlap with charge.succeeded.
		* Per above, if we listen to multiple events, we would need more logic to prevent double-provisioning of subscriptions, as webhook
		order is not guaranteed.
		* For recurring subscriptions, charge.succeeded payload will include the associated invoice that we can fetch additional data from.

		As such, it seems to make the most sense to only listen to charge.succeeded, and fetch the missing meta data for recurring payment
		cases.
		 */

		return [
			// --- Checkout session related (Stripe-side first time payment form submission) ---
			// https://stripe.com/docs/payments/checkout/fulfill-orders#fulfill
			//'checkout.session.completed',
			//'checkout.session.async_payment_succeeded',
			//'checkout.session.async_payment_failed',
			// --- onetime payments ---
			'charge.succeeded',
			'charge.failed',
			'charge.refunded',
			//  --- subscriptions --- https://stripe.com/docs/billing/subscriptions/build-subscriptions?ui=checkout#provision-and-monitor
			// Sent each billing interval when a payment succeeds.
			//'invoice.paid',
			// Sent each billing interval if there is an issue with your customer’s payment method.
			//'invoice.payment_failed',
			'refund.failed',
		];
	}

	private function dosubLength($timeinfo, $subtitle)
	{

		$lengths = array(
			'D' => 'Day',
			'W' => 'Week',
			'M' => 'Month',
			'Y' => 'Year',
			// plural stuff below
			'Ds' => 'Days',
			'Ws' => 'Weeks',
			'Ms' => 'Months',
			'Ys' => 'Years'
		);

		$sublength = ($timeinfo['length'] == 1) ? $timeinfo['length'] . ' ' . $lengths[$timeinfo['units']] : $timeinfo['length'] . ' ' . $lengths[$timeinfo['units'] . 's'];
		
		return $subtitle . ' ' . $sublength;
	}

	/**
	 *
	 * @return string|bool  URL or false on failure.
	 */
	private function generateAdHocPriceURL($hash, $taxOptions, $cost, $currency, $subinfo, $userinfo, $timeinfo)
	{
		if (!$this->initialized)
		{
			return false;
		}

		$subcrTitle = $this->dosubLength($timeinfo, $subinfo['title']);

		$urls = $this->getURLs();
		// Metadata can have up to 50 keys, keynames' max length = 40 chars and values' max length = 500 chars
		// Note that anyone with access to the dashboard can edit an existing record's metadata. This is not
		// encouraged as some metadata, like the hash, is critical to system functionality!
		$metadata = [
			// These are non-critical but potentially handy when looking at items in the
			// Stripe dashboard.
			'url' => $urls['site'],
			'subscription' => $subcrTitle,
			// This is critical for linking the received webhook to a specific paymentinfo
			// record that contains the user & subscription info.
			'hash' => $hash,
			// These metadata are useful for searching in the Stripe dashboard for a specific
			// user's subscription or payment in order to manually cancel it if needed, e.g.
			// "source:vBulletin userid:1234 subscriptionid:5678" in the dashboard searchbar.
			'source' => 'vBulletin',
			'subscriptionid' => $subinfo['subscriptionid'],
			'userid' => $userinfo['userid'],
			//'debug' => 'v4',
		];


		// https://stripe.com/docs/payments/payment-links/api#product-catalog
		// https://stripe.com/docs/products-prices/manage-prices#ad-hoc-prices
		// https://stripe.com/docs/payments/checkout/migrating-prices#server-side-code-for-inline-items
		// https://stripe.com/docs/payments/accept-a-payment?platform=web&ui=checkout#redirect-customers
		try
		{
			// https://stripe.com/docs/api/checkout/sessions/create
			$params = [
				//https://stripe.com/docs/api/checkout/sessions/create#create_checkout_session-line_items
				'line_items' => [[
					'price_data' => $this->getPriceData($taxOptions, $cost, $currency, $subinfo, $timeinfo, $metadata),
					'quantity' => 1,
				]],
				'success_url' => $urls['success'],
				'cancel_url' => $urls['cancel'],

				//A unique string to reference the Checkout Session. This can be a customer ID, a cart ID, or similar, and can be used to reconcile the session with your internal systems.
				// However, this data is only sent with Checkout events, not the charge or invoice events we listen to for actual fulfillment
				'client_reference_id' => $hash,
			];

			// This is sent with Checkout events, particularly 'checkout.session.completed', but not with charge or invoice events.
			$params['metadata'] = array_merge($metadata, ['data_src' => 'Checkout.Sessions.Create']);


			if ($taxOptions['tax'])
			{
				$params['automatic_tax'] = ['enabled' => true];
			}

			//according to https://support.stripe.com/questions/stripe-checkout-and-prices-api ,
			// use mode=subscription with recurring and one-time items (and presumably with only recurring items)
			// and use mode=payment for only one-time items.
			if (!empty($timeinfo['recurring']))
			{
				$params['mode'] = 'subscription';
				// This is sent with Subscription events, particularly 'invoice.paid'
				$params['subscription_data'] = [
					'metadata' => array_merge($metadata, ['data_src' => 'subscription_data']),
					'description' => $subcrTitle,
				];
				// Unfortunately, payment_intent_data is not settable for mode = subscription (& cannot set subscription_data
				// in mode = payment), and the "charge.succeeded" event does not carry the subscription_data metadata (or
				// the session metadata).
				// What's frustrating is that subscription payments still generate a 'charge.succeeded' event in addition to
				// the 'invoice.paid', but per above, that charge event cannot carry the meta data.
				// It would be a lot simpler if we could just set a single set of metadata that's passed to all webhooks, but
				// Stripe API does not currently have such a feature as far as I can tell.
			}
			else
			{
				$params['mode'] = 'payment';
				// This only works for Payment mode, and this data is sent with Charge events, particularly 'charge.succeeded'
				$params['payment_intent_data'] = [
					'metadata' => array_merge($metadata, ['data_src' => 'payment_intent_data']),
					'description' => $subcrTitle,
				];
			}

			// Stripe checkout will automatically create a "Customer" object (visible in the dashboard) which is
			// used to track payments / recurring charges. We do not explicitly create a customer nor track it, so
			// that means each payment will create a new customer. This does mean that their dashboard may become
			// cluttured if they have a lot of different subscriptions that users add, but at the moment I'm not sure
			// if that's a big concern.
			// customer_creation: if_required|always allows a "Guest" checkout session, but ONLY for one-time payments
			// (mode = payment). mode = subscriptions (recurring payments) apparently requires a customer, presumably
			// for tracking the recurring payments.
			// Not seeing a compelling reason to use guest checkouts just for one-time payments as I'm not sure what
			// the benefits of a guest checkout vs implicit customer checkout is, especially if guest checkouts don't
			// work for the preferred subscription mode, but see here if we need to implement this:
			// https://stripe.com/docs/api/checkout/sessions/create#create_checkout_session-customer_creation

			$session = $this->getStripeClient()->checkout->sessions->create($params);

			// debugging
			//$this->log($params);
			//$this->log($session);

			return $session->url;
		}
		catch (Throwable $e)
		{
			$this->log($e);
			$this->handleException($e);
			return false;
		}
	}

	/**
	 *
	 * @return array [
	 * 	'success' => string,
	 *  'cancel' => string,
	 *  'site' => string,
	 * ]
	 */
	private function getURLs()
	{
		$siteurl 	= $this->registry->options['bburl'];
		$successurl = $this->settings['success_url'] ?? $siteurl;
		$cancelurl 	= $this->settings['cancel_url'] ?? $siteurl;
		
		return [
			'success' => $successurl,
			'cancel' => $cancelurl,
			'site' => $siteurl,
		];
	}

	private function getPriceData($taxOptions, $cost, $currency, $subinfo, $timeinfo, $metadata)
	{
		// Stripe API only accepts "cent" values, but the currency doesn't seem to reflect that. E.g. USD not "cents"
		$cost = $this->convertToCents($cost, $currency);
		$subinfotitle = $this->dosubLength($timeinfo, $subinfo['title']);
		$price_data = [
			'unit_amount' => $cost,
			// If we have a product generated, we can use that, but
			// we want to use the ad-hoc/inline product instead.
			//'product' => '{{PRODUCT_ID}}',
			'product_data' => [
				'name' => $subinfotitle,
				// This meta data is retrievable when we fetch an invoice. It is available in the fetched
				// $invoice->lines->data[0]->metadata.
				'metadata' => array_merge($metadata, ['data_src' => 'line_items.price_data.product_data']),
			],
			// https://stripe.com/docs/api/checkout/sessions/create#create_checkout_session-line_items-price_data-currency
			// Supported currencies: https://stripe.com/docs/currencies
			// Stripe API expects LOWER CASE 3-letter ISO codes.
			'currency' => strtolower($currency),
		];

		// Note, for automatic tax, there's also a third 'automatic_tax' param in the outer params block. See caller.
		if (isset($taxOptions['tax']) && $taxOptions['tax']) 
		{
			$price_data['tax_behavior'] = $taxOptions['tax_behavior'];
			if (!empty($taxOptions['tax_category']))
			{
				$price_data['product_data']['tax_code'] = $taxOptions['tax_category'];
			}
		}


		$recurring = $this->getRecurring($timeinfo);
		if (!empty($recurring))
		{
			$price_data['recurring'] = $recurring;
		}

		return $price_data;
	}

	private function getRecurring($timeinfo)
	{
		// stripe only accepts month|year|week|day
		$units_full = [
			'D' => 'day',
			'W' => 'week',
			'M' => 'month',
			'Y' => 'year'
		];
		$isRecurring = !empty($timeinfo['recurring']);
		if (!empty($isRecurring))
		{
			// recurring.interval must be month|year|week|day
			// recurring.interval_count must be positive INTEGER
			// Note, maximum of 1 year interval allowed (e.g. 1 year, 12 months, 52 weeks)
			// todo: enforce this check?
			$recurringData = [
				'interval' => $units_full[$timeinfo['units']],
				'interval_count' => $timeinfo['length'],
			];
		}
		else
		{
			$recurringData = null;
		}

		return $recurringData;
	}

	private function convertToCents($cost, $currency)
	{
		// Stripe & Square APIs (& likely other payment APIs) only accepts "cents" values:
		// https://stripe.com/docs/currencies#zero-decimal
		// In this format, e.g. $1.25, has to be USD 125, apparently. This probably helps
		// avoid various floating point errors that can occur needlessly.
		$currency = strtoupper($currency);

		// These are currencies whose "minor unit" per ISO-4217 is 0 instead of 2.
		// I.e. 1 JPY is the lowest, there's no 1 japanese cent (e.g. sen is not
		// used in ISO-4217)
		$zeroDecimalCurrencies = [
			'BIF',
			'CLP',
			'DJF',
			'GNF',
			'JPY',
			'KMF',
			'KRW',
			'MGA',
			'PYG',
			'RWF',
			'UGX',
			'VND',
			'VUV',
			'XAF',
			'XOF',
			'XPF',
		];
		if (!in_array($currency, $zeroDecimalCurrencies))
		{
			// The special handlings are based around Stripe at the moment, as that's the
			// only modern payment API that seems to have the most currency coverage AND
			// allows for multiple currencies per merchant. If we implement other payment
			// APIs that handle these "special" currencies differently, please override this
			// method!

			// https://stripe.com/docs/currencies#special-cases
			if ($currency == 'HUF' OR $currency == 'TWD' OR $currency == 'UGX')
			{
				$cost = intval($cost) * 100;
			}
			else
			{
				// At the time of this writing, there are some non-cent currencies, like
				// BHD (minorunit = 3, or 1000:1) but Stripe & Square (currently the only
				// APIs that use this method) do not support those currencies.
				// vBulletin seems to only support usd,gbp,eur,aud,cad out of the box
				// for e.g. PayPal, Moneybrookers, and we've currently defaulted Stripe to also
				// only support those currencies (but hypothetically we could add more currencies
				// if customers request them). Note that we're currently bound by the 250 varchar
				// `paymentapi`.`currency` column limit (might need to refactor this..).
				// Square API is a bit special in that in order to handle its API restrction of
				// supporting only ONE currency per store, and having to match the expected currency
				// of the store's locale, we fetch expected currency from Square's API and set it
				// to Square's `paymentapi` record to ensure everything will work. As such, the
				// set currency could hypothetically be outside of any "preset" default currencies
				// mentioned. At the time of writing, Square seems to only support payments in the
				// following countries: Australia, Canada, France, Ireland, Japan, Spain,
				// United Kingdom, United States. (see https://developer.squareup.com/docs/payment-card-support-by-country
				// & https://squareup.com/help/us/en/article/4956-international-availability )
				// Of those countries, only Japan uses a minorunit=0 currency (JPY, in
				// $zeroDecimalCurrencies above), while the others are minorunit=2, so this method
				// should still work for all Square supported currencies.

				// If we begin integrating with payment APIs with wider support than the 0 or 2 minorunit
				// currencies, we should go ahead and map all of the current ISO-4217 currencies and
				// perform proper conversions here instead of just assuming it's minorunit=2 (100:1).
				// For a list, see https://en.wikipedia.org/wiki/ISO_4217#Active_codes

				$cost = $cost * 100;
			}
		}

		// unit_amount MUST be an integer, otherwise it'll throw an exception. unit_amount_decimal seems to be able to handle
		// "fractional cents" depending on currency, but we're not handling that ATM.

		return intval($cost);
	}

	private function convertFromCents($cost, $currency)
	{
		// See notes in convertToCents().
		// Revert from Stripe amount format (e.g. 10 USD for 10 cents) to vB amount format (0.10 USD for 10 cents)

		$currency = strtoupper($currency);

		$zeroDecimalCurrencies = [
			'BIF',
			'CLP',
			'DJF',
			'GNF',
			'JPY',
			'KMF',
			'KRW',
			'MGA',
			'PYG',
			'RWF',
			'UGX',
			'VND',
			'VUV',
			'XAF',
			'XOF',
			'XPF',
		];
		if (!in_array($currency, $zeroDecimalCurrencies))
		{
			$cost = $cost /  100;
		}

		return floatval($cost);
	}

	/**
	* Generates HTML for the subscription form page
	*
	* @param	string		Hash used to indicate the transaction within vBulletin
	* @param	string		The cost of this payment
	* @param	string		The currency of this payment
	* @param	array		Information regarding the subscription that is being purchased
	* @param	array		Information about the user who is purchasing this subscription
	* @param	array		Array containing specific data about the cost and time for the specific subscription period
	*
	* @return	array		Compiled form information
	*/
	public function generate_form_html($hash, $cost, $currency, $subinfo, $userinfo, $timeinfo)
	{
		$url = $this->generateAdHocPriceURL($hash, [], $cost, $currency, $subinfo, $userinfo, $timeinfo);

		if (empty($url)) {
			throw new vB_Exception_Api('Error generating Stripe URL for payment.');
		}

		//  redirect to URL...
		$form['action'] = $url;
		// This is a GET not POST, intentionally. Trying to POST to the checkout URL will hit a 403.
		$form['method'] = 'get';

		return $form;
	}
}

/*=========================================================================*\
|| #######################################################################
|| # Downloaded: 00:22, Sun Mar 17th 2024
|| # CVS: $RCSfile$ - $Revision: 109629 $
|| #######################################################################
\*=========================================================================*/