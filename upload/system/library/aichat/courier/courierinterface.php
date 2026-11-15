<?php
namespace Aichat\Courier;

/**
 * A courier the chat can track shipments with.
 *
 * track() returns the normalised status or null when there is no API / the
 * API failed: the caller then gives the customer only the tracking link,
 * never an error.
 */
interface CourierInterface {
	/**
	 * @param string $number tracking number (digits / letters only)
	 * @param string $lang   two-letter language code of the visitor (bg, en, el)
	 * @return array|null {status, last_event, last_time, location, delivered}
	 *                    status: in_transit | delivered | returned | unknown
	 */
	public function track($number, $lang);

	/** Public tracking page for the number ('' when the courier has none). */
	public function trackingUrl($number);
}
