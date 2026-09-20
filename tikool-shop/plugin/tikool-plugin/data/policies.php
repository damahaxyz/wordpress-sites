<?php
/**
 * Tikool policy pages installed during the source-store migration.
 *
 * The copy intentionally removes platform-specific language and uses tokens that
 * are replaced with the current site URL and support address during installation.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

return [
    'refund-policy' => [
        'title'   => 'Refund Policy',
        'content' => <<<'HTML'
<p><strong>Last updated: September 9, 2026</strong></p>
<p>Because every Tikool item is made from the personalization details you provide, custom orders are final sale once production begins. This does not affect any rights that cannot be excluded under applicable law.</p>

<h2>Starting a return</h2>
<p>Before sending anything back, email us at <a href="mailto:{{support_email}}">{{support_email}}</a> with your order number and a short explanation of your request. If we authorize a return, we will provide the correct instructions. Items returned without authorization may not be accepted.</p>

<h2>Return eligibility</h2>
<p>We will review requests for an item that arrives damaged, defective, or materially different from the personalization details supplied with your order. Contact us promptly and include clear photographs of the item and packaging where relevant. We may offer a replacement, refund, or another appropriate remedy.</p>
<p>We cannot accept returns or exchanges for changes of mind, incorrect personalisation details supplied at checkout, or an uploaded photo that is later changed by the customer, except where required by law.</p>

<h2>Damaged, defective, or incorrect items</h2>
<p>Please inspect your order when it arrives. If an item is defective, damaged, missing, or different from what you ordered, contact <a href="mailto:{{support_email}}">{{support_email}}</a> as soon as possible and include clear photographs where appropriate. We will review the issue and arrange a suitable replacement, refund, or other remedy.</p>

<h2>Exchanges</h2>
<p>The fastest way to obtain a different item is to request a return and, once it is accepted, place a separate order for the replacement.</p>

<h2>European Union cooling-off period</h2>
<p>If an order is delivered into the European Union, you may have a right to cancel or return it within 14 days, subject to applicable exceptions for goods made to the consumer’s specifications or clearly personalized. This does not limit any mandatory consumer rights.</p>

<h2>Refunds</h2>
<p>We will notify you after we have reviewed your request. If a refund is approved, it will be issued to your original payment method within 10 business days. Your bank or payment provider may require additional time to post the funds to your account.</p>
<p>If more than 15 business days have passed since we approved your refund, please email <a href="mailto:{{support_email}}">{{support_email}}</a>.</p>

<h2>Questions</h2>
<p>For any return or refund question, contact Tikool at <a href="mailto:{{support_email}}">{{support_email}}</a>.</p>
HTML,
    ],
    'privacy-policy' => [
        'title'   => 'Privacy Policy',
        'content' => <<<'HTML'
<p><strong>Last updated: September 9, 2026</strong></p>
<p>This Privacy Policy explains how Tikool collects, uses, shares, and protects personal information when you visit {{site_url}}, create an account, place an order, contact us, submit a review, or otherwise use our website and services.</p>

<h2>Information we collect</h2>
<p>Depending on how you use the site, we may collect:</p>
<ul>
<li><strong>Contact and account information</strong>, including your name, email address, telephone number, login details, and saved preferences.</li>
<li><strong>Order and delivery information</strong>, including billing and shipping addresses, products purchased, order history, returns, and tracking details.</li>
<li><strong>Payment information</strong>. Payments are processed by payment providers. Tikool generally receives transaction identifiers, payment status, and limited payment details rather than complete card data.</li>
<li><strong>Device and usage information</strong>, including IP address, browser type, device identifiers, referring pages, pages viewed, interactions, and cookie data.</li>
<li><strong>Communications and user content</strong>, including support requests, survey responses, product reviews, ratings, and photographs you choose to upload.</li>
</ul>

<h2>How we use information</h2>
<p>We use personal information to operate the store, process payments and orders, create personalized products from the photos and details you submit, deliver products, provide tracking and customer support, administer returns, maintain accounts, publish submitted reviews, prevent fraud and abuse, comply with legal obligations, improve our website and products, and send service or marketing communications where permitted.</p>

<h2>Cookies and similar technologies</h2>
<p>Tikool and its service providers may use cookies and similar technologies to keep the cart and checkout working, remember preferences, secure the site, understand performance, measure traffic, and support advertising or analytics where enabled. You can control cookies through your browser. Blocking essential cookies may prevent parts of the store from working correctly.</p>

<h2>How we share information</h2>
<p>We may share personal information with vendors that help us operate the store, including website hosting and ecommerce providers, payment processors, fraud-prevention services, analytics providers, customer-support tools, warehouses, delivery carriers, and professional advisers. We may also disclose information when required by law, to protect rights and safety, or as part of a merger, financing, acquisition, or transfer of the business.</p>
<p>We do not sell personal information for money. Some privacy laws define certain analytics or advertising disclosures as “selling” or “sharing”; where those laws apply, you may have the right to opt out.</p>

<h2>International processing</h2>
<p>Our service providers may process information in countries other than the country where you live. Where required, we use appropriate safeguards for international transfers.</p>

<h2>Retention and security</h2>
<p>We retain information for as long as reasonably necessary to provide services, create and support personalized orders, maintain business and tax records, resolve disputes, enforce agreements, and meet legal obligations. Customer reference photos are stored in a protected order area and are available only to authorized staff for order fulfillment and support. We use reasonable administrative, technical, and organizational safeguards, but no online system can be guaranteed completely secure.</p>

<h2>Your choices and rights</h2>
<p>Depending on where you live, you may have rights to access, correct, delete, restrict, or receive a copy of your personal information; object to certain processing; withdraw consent; or opt out of certain marketing and data sharing. We may need to verify your identity before completing a request. You may unsubscribe from marketing emails by using the link in the message.</p>

<h2>Children</h2>
<p>Our services are not directed to children, and we do not knowingly collect personal information from children in violation of applicable law. If you believe a child has provided information to us, please contact us.</p>

<h2>Third-party websites</h2>
<p>The site may contain links to third-party websites. Their privacy practices are governed by their own policies, and Tikool is not responsible for those practices.</p>

<h2>Changes to this policy</h2>
<p>We may update this Privacy Policy to reflect operational, legal, or regulatory changes. The updated version will be posted here with a revised date.</p>

<h2>Contact us</h2>
<p>For privacy questions or requests, email Tikool at <a href="mailto:{{support_email}}">{{support_email}}</a>.</p>
HTML,
    ],
    'terms-of-service' => [
        'title'   => 'Terms of Service',
        'content' => <<<'HTML'
<p><strong>Last updated: September 9, 2026</strong></p>
<p>Welcome to Tikool. These Terms of Service govern your access to and use of {{site_url}}, our online store, and the products and services made available through it (collectively, the “Services”). By using the Services, you agree to these Terms and our <a href="/privacy-policy/">Privacy Policy</a>. If you do not agree, do not use the Services.</p>

<h2>1. Eligibility and accounts</h2>
<p>You must be at least the age of majority in your place of residence, or use the Services with the consent and supervision required by applicable law. Information you provide must be accurate, current, and complete. You are responsible for protecting your account credentials and for activity under your account.</p>

<h2>2. Products and information</h2>
<p>We try to describe and display products accurately, but colors, materials, packaging, and appearance may vary by screen, production run, or supplier. Personalized artwork is created from the reference photo and details you provide; artistic interpretation, printing, and screen displays may vary.</p>
<p>We may update descriptions, prices, availability, purchase limits, or product offerings at any time without notice.</p>

<h2>3. Orders</h2>
<p>Submitting an order is an offer to purchase. An order is accepted only after payment is authorized and Tikool confirms acceptance. We may decline, limit, correct, or cancel an order for reasons including availability, suspected fraud, pricing errors, or shipping restrictions. If we change or cancel an order, we will attempt to contact you using the details supplied at checkout.</p>
<p>Purchases are intended for personal or household use unless Tikool agrees otherwise in writing.</p>

<h2>4. Prices, billing, and promotions</h2>
<p>Prices, discounts, and promotions may change without notice. The price charged is the price shown when your order is placed, subject to correction of obvious errors. Unless stated otherwise, taxes, duties, customs charges, and shipping costs may be added at checkout or charged by the destination country.</p>
<p>You confirm that you are authorized to use the payment method supplied and agree to provide complete and accurate billing information.</p>

<h2>5. Shipping and delivery</h2>
<p>Delivery dates are estimates rather than guarantees. Carrier delays, customs processing, weather, incorrect addresses, and events outside our reasonable control may affect delivery. Please see our <a href="/shipping-policy/">Shipping Policy</a> for current processing and delivery information.</p>

<h2>6. Returns and refunds</h2>
<p>Returns, exchanges, and refunds are governed by our <a href="/refund-policy/">Refund Policy</a>, which forms part of these Terms.</p>

<h2>7. Acceptable use</h2>
<p>You may use the Services only for lawful purposes. You must not violate laws or third-party rights; introduce malware; attempt unauthorized access; interfere with security or operation; scrape or harvest personal information; submit deceptive information; impersonate another person; or use the Services to distribute spam, abusive, defamatory, obscene, or unlawful material.</p>

<h2>8. Intellectual property</h2>
<p>The Services and their text, design, graphics, images, video, audio, logos, trademarks, and other content are owned by Tikool or its licensors and are protected by applicable intellectual-property laws. You may use the site for personal, non-commercial shopping purposes only. No other license is granted without prior written permission.</p>

<h2>9. Reviews and feedback</h2>
<p>If you submit a pet photo, review, suggestion, or other content, you confirm that you own or control the necessary rights and that the content is accurate and lawful. You grant Tikool a non-exclusive, royalty-free license to store, reproduce, and adapt that content only as needed to make, fulfill, support, and improve your order. We will not use a customer’s pet photo in marketing without separate permission. We may moderate or remove content that violates these Terms.</p>

<h2>10. Third-party services and links</h2>
<p>The Services may rely on or link to third-party providers. We are not responsible for third-party websites, content, products, terms, or privacy practices. Your use of a third-party service is at your own risk and may be governed by separate terms.</p>

<h2>11. Errors and service changes</h2>
<p>Information may occasionally contain typographical errors, inaccuracies, or omissions relating to descriptions, prices, promotions, availability, or delivery. We may correct information, update the Services, or cancel affected orders as permitted by law.</p>

<h2>12. Disclaimer of warranties</h2>
<p>To the fullest extent permitted by law, the Services and products are provided “as is” and “as available” except for warranties expressly stated by Tikool or required by law. We do not guarantee uninterrupted, timely, secure, or error-free access. Nothing in these Terms excludes mandatory consumer guarantees that cannot legally be excluded.</p>

<h2>13. Limitation of liability</h2>
<p>To the fullest extent permitted by law, Tikool and its affiliates, personnel, contractors, and service providers will not be liable for indirect, incidental, punitive, special, or consequential losses arising from use of the Services or products. Where liability cannot be excluded, it will be limited to the amount paid for the affected order, except where a greater remedy is required by law.</p>

<h2>14. Indemnification</h2>
<p>To the extent permitted by law, you agree to indemnify and hold Tikool harmless from third-party claims and reasonable costs arising from your unlawful use of the Services, violation of these Terms, or infringement of another person’s rights.</p>

<h2>15. Suspension and termination</h2>
<p>We may suspend or terminate access if we reasonably believe you have violated these Terms or created risk for Tikool, customers, or third parties. Provisions that by their nature should survive termination will remain in effect.</p>

<h2>16. Severability, waiver, and assignment</h2>
<p>If a provision is unenforceable, it will be limited or removed to the minimum extent necessary and the remaining provisions will continue. Failure to enforce a provision is not a waiver. You may not assign these Terms without our consent; Tikool may assign them as part of a business transfer or reorganization.</p>

<h2>17. Governing law</h2>
<p>These Terms are governed by the laws applicable where Tikool has its principal place of business, without limiting any mandatory consumer rights you have under the laws of your residence.</p>

<h2>18. Changes to these Terms</h2>
<p>We may update these Terms by publishing a revised version on this page. Material changes will take effect as required by applicable law. Continued use after the effective date constitutes acceptance of the updated Terms.</p>

<h2>19. Contact</h2>
<p>Questions about these Terms should be sent to Tikool at <a href="mailto:{{support_email}}">{{support_email}}</a>.</p>
HTML,
    ],
    'shipping-policy' => [
        'title'   => 'Shipping Policy',
        'content' => <<<'HTML'
<p><strong>Last updated: September 9, 2026</strong></p>
<p>Tikool creates each personalized item after an order is placed. Production and delivery timing varies by product, destination, and carrier. Any estimate shown at checkout begins after production and is not guaranteed.</p>

<h2>Order processing</h2>
<p>Custom orders are prepared on business days. Orders placed during weekends, holidays, major promotions, or periods of unusually high demand may require additional production time. We will contact you if a product becomes unavailable or a significant delay is expected.</p>

<h2>Tracking</h2>
<p>When your parcel ships, tracking information will be sent to the email address used at checkout. Tracking events can take several days to appear after the label is created. You can also use our <a href="/track-order/">Track order</a> page.</p>

<h2>Delivery estimates and delays</h2>
<p>Delivery times vary by location and carrier. Customs inspections, local postal operations, weather, public holidays, incorrect address details, and other events outside Tikool’s control may cause delays. A delay beyond the estimate does not automatically mean a parcel is lost.</p>

<h2>Addresses</h2>
<p>Please check your shipping address before submitting an order. If you notice an error, contact <a href="mailto:{{support_email}}">{{support_email}}</a> immediately. We cannot guarantee that an address can be changed after processing begins, and additional shipping charges may apply if a parcel must be resent.</p>

<h2>Customs, duties, and taxes</h2>
<p>International orders may be subject to import duties, taxes, customs fees, or local handling charges. Unless checkout states otherwise, these charges are the recipient’s responsibility and are not controlled by Tikool.</p>

<h2>Missing or damaged parcels</h2>
<p>If tracking shows delivery but you cannot find the parcel, check with household members, neighbors, the carrier, and any secure delivery location first. For parcels that arrive damaged or appear lost, email <a href="mailto:{{support_email}}">{{support_email}}</a> with your order number and any relevant photographs or tracking details so we can investigate.</p>

<h2>Returns</h2>
<p>Every purchase is covered by our <a href="/refund-policy/">Refund Policy</a>. Contact us before returning an item so we can provide the correct instructions.</p>

<h2>Questions</h2>
<p>For shipping assistance, contact Tikool at <a href="mailto:{{support_email}}">{{support_email}}</a>.</p>
HTML,
    ],
];
