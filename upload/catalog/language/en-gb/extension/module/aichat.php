<?php
// AI Chat - storefront widget strings (keys widget_* are sent to the browser in boot.strings)
$_['widget_ai_label']    = 'AI assistant';
$_['widget_placeholder'] = 'Type your message…';
$_['widget_send']        = 'Send';
$_['widget_open']        = 'Open chat';
$_['widget_close']       = 'Close chat';
$_['widget_emoji']       = 'Emoji';
$_['widget_typing']      = 'The assistant is typing';
$_['widget_error']       = 'Something went wrong. Please try again.';
$_['widget_too_long']    = 'Message is too long';
$_['widget_chars']       = 'characters';
$_['widget_greeting']    = 'Hello! I am %s, the AI assistant of the store. How can I help you?';
$_['widget_messages']    = 'Conversation';
$_['widget_suggestion']  = 'You may also need';

// Bot name when none is set in the admin
$_['widget_welcome_back'] = 'Welcome back! Can I help you with anything else?';
$_['widget_view_order']   = 'View order';
$_['widget_track']        = 'Track shipment';
$_['widget_challenge']    = 'Checking that you are not a robot…';

// Cards (built by the server)
$_['card_special']      = 'Special offers';
$_['card_contact']      = 'Contact us';
$_['card_return']       = 'Returns form';
$_['card_return_order'] = 'Order #%s';
$_['card_order']        = 'Order #%s';
$_['card_view_order']   = 'View order';
$_['card_track']        = 'Track shipment';
$_['card_delivered']    = 'Delivered';

$_['default_bot_name']   = 'Alex';

// Errors (already localised, safe to show)
$_['error_disabled']       = 'The chat is not available at the moment.';
$_['error_method']         = 'Invalid request.';
$_['error_invalid_token']  = 'Your chat session has expired. Please reload the page.';
$_['error_empty']          = 'Please type a message.';
$_['error_too_long']       = 'Your message is too long. Please shorten it to %s characters.';
$_['error_rate_limited']   = 'You are sending messages too quickly. Please wait a moment.';
$_['error_chat_limit']     = 'This conversation has reached its message limit. Please contact us directly: %s';
$_['error_chat_day_limit'] = 'Too many conversations started today. Please contact us directly: %s';
$_['error_budget']         = 'The assistant is not available right now. Please contact us directly: %s';
$_['error_api_error']      = 'The assistant could not answer right now. Please try again in a minute.';
$_['error_refusal']        = 'Sorry, I cannot help with that. For anything about our products, just ask!';
$_['error_turnstile']      = 'Please confirm that you are not a robot and try again.';
$_['error_contacts_page']  = 'see our Contact page';
$_['error_challenge']      = 'Please wait a moment, we are checking that you are not a robot.';

// Round 3: widget (sent in boot.strings without the widget_ prefix)
$_['widget_attach']           = 'Attach a photo';
$_['widget_img_max']     = 'Up to 3 photos per message';
$_['widget_remove']     = 'Remove photo';
$_['widget_helpful']          = 'Helpful';
$_['widget_not_helpful']        = 'Not helpful';
$_['widget_add_cart']      = 'Add to cart';
$_['widget_options_required']   = 'Choose options';
$_['widget_view_cart']        = 'View cart';
$_['widget_menu']             = 'More';
$_['widget_transcript']       = 'E-mail me this conversation';
$_['widget_email_ph'] = 'Your e-mail';
$_['widget_forget']           = 'Delete this conversation';
$_['widget_forget_ask']   = 'Delete this conversation and its photos for good?';
$_['widget_cancel']           = 'Cancel';
$_['widget_yes']             = 'Yes, delete';
$_['widget_unmute']          = 'Turn sounds on';
$_['widget_mute']            = 'Mute sounds';
$_['widget_connecting']          = 'Waiting for a colleague to join…';
$_['widget_colleague']            = 'Team';
$_['widget_proactive']        = 'Hi! Need help choosing? Ask me anything 🙂';

// Round 3: errors
$_['error_image_too_large']  = 'The photo is too large (max. %s).';
$_['error_image_type']       = 'Only JPEG, PNG or WebP photos can be sent.';
$_['error_image_bad']        = 'This photo could not be read. Please try another one.';
$_['error_image_count']      = 'Too many photos in this conversation.';
$_['error_image_storage']    = 'The photo could not be saved. Please try again later.';
$_['error_image_limit']      = 'Up to %s photos per message.';
$_['error_image_not_found']  = 'A photo is no longer available. Please attach it again.';
$_['error_feature_off']      = 'This function is not available.';
$_['error_not_found']        = 'Not found.';
$_['error_invalid_email']    = 'Please enter a valid e-mail address.';
$_['error_cart_product']     = 'This product cannot be added to the cart.';
$_['error_cart_login']       = 'Please log in to buy this product.';
$_['error_cart_options']     = 'Please choose the options on the product page.';
$_['error_cart_minimum']     = 'The minimum quantity for this product is %s.';
$_['error_cart_stock']       = 'Sorry, this quantity is not available right now.';

// Round 3: messages written by the server
$_['text_cart_added']        = '%s was added to your cart.';
$_['text_transcript_sent']   = 'The conversation was sent to %s.';
$_['text_forgotten']         = 'The conversation was deleted.';
$_['transcript_subject']     = '%s: your chat conversation';
$_['transcript_intro']       = 'Your conversation with the assistant of %s:';
$_['transcript_you']         = 'You';
$_['transcript_staff']       = 'Team';
$_['handover_joined']        = '%s joined the chat';
$_['handover_done']          = 'The assistant is back. How else can I help?';
$_['handover_timeout']       = 'Sorry, nobody from our team is free right now. Leave your e-mail or phone number and we will get back to you as soon as possible.';
$_['handover_wait']          = 'I am transferring you to a colleague. This may take up to %s. Please wait.';
$_['wait_second_one']        = '%d second';
$_['wait_second_many']       = '%d seconds';
$_['wait_minute_one']        = '%d minute';
$_['wait_minute_many']       = '%d minutes';
$_['widget_img_caption']            = 'Photo';
$_['widget_img_type']         = 'Only JPEG, PNG or WebP photos can be sent.';
$_['widget_img_big']          = 'This photo is too large.';

// security round (1.0.2)
$_['error_forbidden'] = 'This request was refused. Please reload the page.';
$_['error_busy'] = 'Many customers are chatting right now. Please try again in a moment.';
$_['error_cost_limit'] = 'The chat limit for today has been reached. Please contact us directly: %s';
$_['error_transcript_unavailable'] = 'The conversation can be e-mailed only to the address of your account or of an order checked in this chat.';
$_['text_transcript_unavailable'] = 'You can e-mail the conversation to yourself after logging in to your account, or after checking an order in this chat (it then goes to the order’s e-mail address).';
$_['widget_transcript_confirm'] = 'Send the conversation to %s?';
