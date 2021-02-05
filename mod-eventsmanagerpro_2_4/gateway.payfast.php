<?php
/**
 * gateway.payfast.php
 *
 * @author     Cate
 * @version    1.1.0
 */

include 'payfast_common.inc';

class EM_Gateway_PayFast extends EM_Gateway
{
    var $gateway = 'payfast';
    var $title = 'PayFast';
    var $status = 4;
    var $status_txt = 'Awaiting payment via PayFast';
    var $button_enabled = false;
    var $payment_return = true;
    var $count_pending_spaces = true;
    var $supports_multiple_bookings = true;

    // Sets up gateaway and adds relevant actions/filters
    function __construct()
    {
        // Booking Interception
        if ( $this->is_active() && absint( get_option( 'em_'.$this->gateway.'_booking_timeout' ) ) > 0 )
        {
            $this->count_pending_spaces = true;
        }

        parent::__construct();
        $this->status_txt = __('Awaiting payment via PayFast', 'em-pro');
        if ( $this->is_active() )
        {
            add_action( 'em_gateway_js', array( &$this, 'em_gateway_js' ) );
            // Gateway-Specific
            add_action( 'em_template_my_bookings_header', array( &$this, 'say_thanks' ) ); //say thanks on my_bookings page
            add_filter( 'em_bookings_table_booking_actions_4', array( &$this, 'bookings_table_actions' ), 1, 2 );
            add_filter( 'em_my_bookings_booking_actions', array( &$this, 'em_my_bookings_booking_actions' ), 1, 2 );

            add_action('em_handle_payment_return_' . $this->gateway, array(&$this, 'handle_payment_return'));
            // Set up cron
            $timestamp = wp_next_scheduled( 'emp_payfast_cron' );
            if
            ( absint( get_option( 'em_payfast_booking_timeout' ) ) > 0 && !$timestamp )
            {
                $result = wp_schedule_event( time(), 'em_minute', 'emp_payfast_cron' );
            }
            elseif ( !$timestamp )
            {
                wp_unschedule_event($timestamp, 'emp_payfast_cron');
            }
        }
        else
        {
            // Unschedule the cron
            wp_clear_scheduled_hook( 'emp_payfast_cron' );
        }
    }

    // Intercepts return data after a booking has been made and adds PayFast vars, modifies feedback message.
    function booking_form_feedback( $return, $EM_Booking = false )
    {
        // Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.
        if ( is_object( $EM_Booking ) && $this->uses_gateway( $EM_Booking ) )
        {
            if ( !empty( $return['result'] ) && $EM_Booking->get_price() > 0 && $EM_Booking->booking_status == $this->status )
            {
                $return['message'] = get_option( 'em_payfast_booking_feedback' );
                $payfast_url = $this->get_payfast_url();
                $payfast_vars = $this->get_payfast_vars( $EM_Booking );
                $payfast_return = array( 'payfast_url'=>$payfast_url, 'payfast_vars'=>$payfast_vars );
                $return = array_merge( $return, $payfast_return );
            }
            else
            {
                // Returning a free message
                $return['message'] = get_option( 'em_payfast_booking_feedback_free' );
            }
        }
        return $return;
    }

    // Called if AJAX isn't being used, i.e. a javascript script failed and forms are being reloaded instead.
    function booking_form_feedback_fallback( $feedback )
    {
        global $EM_Booking;
        if ( is_object( $EM_Booking ) )
        {
            $feedback .= "<br />" . __( 'To finalize your booking, please click the following button to proceed to PayFast.', 'em-pro' ). $this->em_my_bookings_booking_actions('', $EM_Booking );
        }
        return $feedback;
    }

    // Triggered by the em_booking_add_yourgateway action, hooked in EM_Gateway. Overrides EM_Gateway to account for non-ajax bookings (i.e. broken JS on site).
    function booking_add( $EM_Event, $EM_Booking, $post_validation = false )
    {
        parent::booking_add( $EM_Event, $EM_Booking, $post_validation );
        if( !defined( 'DOING_AJAX' ) )
        { //we aren't doing ajax here, so we should provide a way to edit the $EM_Notices ojbect.
            add_action( 'option_dbem_booking_feedback', array( &$this, 'booking_form_feedback_fallback' ) );
        }
    }

    // Instead of a simple status string, a resume payment button is added to the status message so user can resume booking from their my-bookings page.
    function em_my_bookings_booking_actions( $message, $EM_Booking )
    {
        global $wpdb;
        if ( $this->uses_gateway( $EM_Booking ) && $EM_Booking->booking_status == $this->status )
        {
            // First make sure there's no pending payments
            $pending_payments = $wpdb->get_var( 'SELECT COUNT(*) FROM '.EM_TRANSACTIONS_TABLE. " WHERE booking_id='{$EM_Booking->booking_id}' AND transaction_gateway='{$this->gateway}' AND transaction_status='Pending'" );
            if( $pending_payments == 0 )
            {
                //user owes money!
                $payfast_vars = $this->get_payfast_vars( $EM_Booking );
                $form = '<form action="'.$this->get_payfast_url().'" method="post">';
                foreach ( $payfast_vars as $key=>$value )
                {
                    $form .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
                }
                //            $form .= '<input type="submit" value="'.__('Awaiting Confirmation','em-pro').'">';
                //            $form .= '</form>';
                $message = 'Awaiting Confirmation';
            }
        }
        return $message;
    }

    // Outputs extra custom content
    function booking_form()
    {
        echo get_option( 'em_'.$this->gateway.'_form' );
    }

    // Outputs some JavaScript during the em_gateway_js action, which is run inside a script html tag, located in gateways/gateway.payfast.js
    function em_gateway_js()
    {
        include( dirname( __FILE__ ).'/gateway.payfast.js' );
    }

    // Adds relevant actions to booking shown in the bookings table
    function bookings_table_actions( $actions, $EM_Booking )
    {
        return array(
            'approve' => '<a class="em-bookings-approve em-bookings-approve-offline" href="'.em_add_get_params( $_SERVER['REQUEST_URI'], array( 'action'=>'bookings_approve', 'booking_id'=>$EM_Booking->booking_id ) ).'">'.esc_html__emp( 'Approve','dbem' ).'</a>',
            'delete' => '<span class="trash"><a class="em-bookings-delete" href="'.em_add_get_params( $_SERVER['REQUEST_URI'], array( 'action'=>'bookings_delete', 'booking_id'=>$EM_Booking->booking_id ) ).'">'.esc_html__emp( 'Delete','dbem' ).'</a></span>',
            'edit' => '<a class="em-bookings-edit" href="'.em_add_get_params( $EM_Booking->get_event()->get_bookings_url(), array( 'booking_id'=>$EM_Booking->booking_id, 'em_ajax'=>null, 'em_obj'=>null ) ).'">'.esc_html__emp( 'Edit/View','dbem' ).'</a>',
        );
    }

    function get_payfast_vars( $EM_Booking )
    {
        global $wp_rewrite, $EM_Notices;
        $notify_url = $this->get_payment_return_url();
        $payfast_vars = array();
        $pf_merchant_id = get_option( 'em_' . $this->gateway . "_merchant_id" );
        $pf_merchant_key = get_option( 'em_' . $this->gateway . "_merchant_key" );
        if (  ( get_option( 'em_' . $this->gateway . "_status" ) == 'test' ) and ( empty( $pf_merchant_id ) or empty( $pf_merchant_key ) ) )
        {
            $payfast_vars['merchant_id'] = '10000100';
            $payfast_vars['merchant_key'] = '46f0cd694581a';
            $passPhrase = "";
        }
        else
        {
            $payfast_vars['merchant_id'] = $pf_merchant_id;
            $payfast_vars['merchant_key'] = $pf_merchant_key;
            $passPhrase =  get_option( 'em_'. $this->gateway . "_passphrase");
        }
        if ( !empty( get_option( 'em_'. $this->gateway . "_return" ) ) )
        {
            $payfast_vars['return_url'] = get_option( 'em_' . $this->gateway . "_return" );
        }

        if ( !empty( get_option( 'em_'. $this->gateway . "_cancel_return" ) ) )
        {
            $payfast_vars['cancel_url'] = get_option( 'em_' . $this->gateway . "_cancel_return" );
        }

        $payfast_vars['notify_url'] = $notify_url;

        $payfast_vars['name_first'] = $EM_Booking->get_person()->get_name();
            // $payfast_vars['name_last'] = $EM_Booking->get_person()->last_name;
            // $payfast_vars['email_address'] = $EM_Booking->get_person()->user_email;

        $payfast_vars['amount'] = $EM_Booking->get_price();
        $payfast_vars['item_name'] = $EM_Booking->get_event()->event_name;

        $payfast_vars['custom_int1'] = $EM_Booking->booking_id;
        // 'custom_str1' => $EM_Booking->event_id,
        $payfast_vars['custom_str1'] = 'PF_EMPro_2.4_'.constant('PF_MODULE_VER');

        $pfOutput = '';
        // Create output string
        foreach ( $payfast_vars as $key => $val )
            $pfOutput .= $key .'='. urlencode( trim( $val ) ) .'&';

            if( empty( $passPhrase ) )
            {
                $pfOutput = substr( $pfOutput, 0, -1 );
            }
            else
            {
                $pfOutput = $pfOutput."passphrase=".urlencode( $passPhrase );
            }

            $payfast_vars['signature'] = md5( $pfOutput );
            $payfast_vars['user_agent'] = 'Events Manager Pro 2';

        return apply_filters('em_gateway_payfast_get_payfast_vars', $payfast_vars, $EM_Booking, $this);
    }

    function get_payfast_url()
    {
        return ( get_option( 'em_'. $this->gateway . "_status" ) == 'test' ) ? 'https://sandbox.payfast.co.za/eng/process':'https://www.payfast.co.za/eng/process';
    }

    function say_thanks()
    {
        if ( !empty( $_REQUEST['thanks'] ) )
        {
            echo "<div class='em-booking-message em-booking-message-success'>".get_option( 'em_'.$this->gateway.'_booking_feedback_thanks' ).'</div>';
        }
    }

    function handle_payment_return()
    {
        if ( empty( $_POST['payment_status'] ) || empty( $_POST['pf_payment_id'] ) )
        {
            return false;
        }

        require_once ('payfast_common.inc');
        pflog( 'PayFast ITN call received' );

        $pfError = false;
        $pfErrMsg = '';
        $pfDone = false;
        $pfData = array();
        $pfParamString = '';
        $pfHost = ( get_option( 'em_'. $this->gateway . "_status" ) == 'test' ) ? 'sandbox.payfast.co.za':'www.payfast.co.za';

        //// Notify PayFast that information has been received
        if( !$pfError && !$pfDone )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }

        if( !$pfError && !$pfDone )
        {
            pflog( 'Get posted data' );

            // Posted variables from ITN
            $pfData = pfGetData();

            pflog( 'PayFast Data: '. print_r( $pfData, true ) );

            if( $pfData === false )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Verify security signature
        if( !$pfError )
        {
            pflog( 'Verify security signature' );


            $pf_merchant_id = get_option( 'em_' . $this->gateway . "_merchant_id" );
            $pf_merchant_key = get_option( 'em_' . $this->gateway . "_merchant_key" );
            $passPhrase = get_option( 'em_'. $this->gateway . "_passphrase");
            $pfPassphrase = (empty( $passPhrase ) or empty( $pf_merchant_id ) or empty( $pf_merchant_key )  ) ? null : $passPhrase;

            // If signature different, log for debugging
            if( !pfValidSignature( $pfData, $pfParamString, $pfPassphrase ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
            }
        }

        if( !$pfError && !$pfDone )
        {
            pflog( 'Verify source IP' );

            if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
            }
        }

        if( !$pfError )
        {
            pflog( 'Verify data received' );

            $pfValid = pfValidData( $pfHost, $pfParamString );

            if( !$pfValid )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        if ( $pfError )
        {
            pflog('Error occurred: ' . $pfErrMsg);
        }

        // Handle cases that the system must ignore
        if( !$pfError && !$pfDone )
        {
            pflog( 'check status and update order' );

            $new_status = false;
            // Common variables
            $amount = $_POST['amount_gross'];
            $currency = 'ZAR';
            $timestamp = date('Y-m-d H:i:s');
            $booking_id = $_POST['custom_int1'];
            // $event_id = $_POST['custom_str1'];
            $EM_Booking = $EM_Booking = em_get_booking($booking_id);
            // Booking exists
            // Override the booking ourselves:
            $EM_Booking->manage_override = true;
            $user_id = $EM_Booking->person_id;

            // Process PayFast response
            switch ($_POST['payment_status']) {
                case 'COMPLETE':
                    pflog('-Complete');
                    // Case: successful payment
                    $this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['pf_payment_id'], $_POST['payment_status'], '');

                    if ($_POST['amount_gross'] >= $EM_Booking->get_price() && (!get_option('em_' . $this->gateway . '_manual_approval', false) || !get_option('dbem_bookings_approval'))) {
                        // Approve and ignore spaces
                        $EM_Booking->approve(true, true);
                    } else {
                        // TODO do something if pp payment not enough
                        $EM_Booking->set_status(0); //Set back to normal "pending"
                    }
                    do_action('em_payment_processed', $EM_Booking, $this);
                    break;
                case 'FAILED':
                    pflog('- Failed');
                    // Case: denied
                    $note = 'Last transaction failed';
                    $this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['pf_payment_id'], $_POST['payment_status'], $note);
                    $EM_Booking->cancel();
                    do_action('em_payment_denied', $EM_Booking, $this);
                    break;
                case 'PENDING':
                    pflog('- Pending');
                    // Case: pending
                    $note = 'Last transaction is pending. Reason: ' . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);
                    $this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);
                    do_action('em_payment_pending', $EM_Booking, $this);
                    break;
                default:
                    // If unknown status, do nothing (safest course of action)
                    break;
            }
        }
    }

    public static function payment_return_local_ca_curl( $handle )
    {
        curl_setopt( $handle, CURLOPT_CAINFO, dirname(__FILE__).DIRECTORY_SEPARATOR.'gateway.payfast.pem' );
    }

    function mysettings() {
        global $EM_options;
        ?>
        <table class="form-table">
            <tbody>
            <tr valign="top">
                <th scope="row"><?php _e( 'Redirect Message', 'em-pro' ) ?></th>
                <td>
                    <input type="text" name="payfast_booking_feedback" value="<?php esc_attr_e( get_option( 'em_'. $this->gateway . "_booking_feedback" ) ); ?>" style='width: 40em;' /><br />
                    <em><?php _e( 'The message that is shown before a user is redirected to PayFast.','em-pro' ); ?></em>
                </td>
            </tr>
            </tbody>
        </table>

        <h3><?php echo sprintf(__( '%s Options','em-pro' ),'PayFast' ); ?></h3>
        <table class="form-table">
            <tbody>
            <tr valign="top">
                <th scope="row"><?php _e( 'Merchant ID', 'em-pro' ) ?></th>
                <td><input type="text" name="merchant_id" value="<?php esc_attr_e( get_option( 'em_'. $this->gateway . "_merchant_id" ) ); ?>" />
                    <br />
                </td>
            </tr>
            <tbody>
            <tr valign="top">
                <th scope="row"><?php _e( 'Merchant Key', 'em-pro' ) ?></th>
                <td><input type="text" name="merchant_key" value="<?php esc_attr_e( get_option( 'em_'. $this->gateway . "_merchant_key" ) ); ?>" />
                    <br />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e( 'Passphrase', 'em-pro' ) ?></th>
                <td><input type="text" name="passphrase" value="<?php esc_attr_e( get_option( 'em_'. $this->gateway . "_passphrase" ) ); ?>" />
                    <br />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e( 'Mode', 'em-pro' ) ?></th>
                <td>
                    <select name="payfast_status">
                        <option value="live" <?php if ( get_option( 'em_'. $this->gateway . "_status" ) == 'live' ) echo 'selected="selected"'; ?>><?php _e( 'Live', 'em-pro' ) ?></option>
                        <option value="test" <?php if ( get_option( 'em_'. $this->gateway . "_status" ) == 'test' ) echo 'selected="selected"'; ?>><?php _e( 'Test Mode (Sandbox)', 'em-pro' ) ?></option>
                    </select>
                    <br />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e( 'Debug', 'em-pro' ) ?></th>
                <td>
                    <select name="payfast_debug">
                        <option value="true" <?php if ( get_option( 'em_'. $this->gateway . "_debug" ) == 'true' ) echo 'selected="selected"'; ?>><?php _e( 'On', 'em-pro' ) ?></option>
                        <option value="false" <?php if ( get_option( 'em_'. $this->gateway . "_debug" ) == 'false' ) echo 'selected="selected"'; ?>><?php _e( 'Off', 'em-pro' ) ?></option>
                    </select>
                    <br />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e( 'Return URL', 'em-pro' ) ?></th>
                <td>
                    <input type="text" name="payfast_return" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return" )); ?>" style='width: 40em;' /><br />
                    <em><?php _e( 'The URL of the page the user is returned to after payment.', 'em-pro' ); ?></em>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e( 'Cancel URL', 'em-pro' ) ?></th>
                <td>
                    <input type="text" name="payfast_cancel_return" value="<?php esc_attr_e( get_option( 'em_'. $this->gateway . "_cancel_return" ) ); ?>" style='width: 40em;' /><br />
                    <em><?php _e( 'If a user cancels, they will be redirected to this page.', 'em-pro' ); ?></em>
                </td>
            </tr>
            </tbody>
        </table>
    <?php
    }

    function update()
    {
        // parent::update();
        $gateway_options = array(
            $this->gateway . "_merchant_id" => $_REQUEST['merchant_id'],
            $this->gateway . "_merchant_key" => $_REQUEST['merchant_key'],
            $this->gateway . "_passphrase" => $_REQUEST['passphrase'],
            $this->gateway . "_currency" => $_REQUEST[ 'currency' ],
            // $this->gateway . "_inc_tax" => $_REQUEST[ 'em_'.$this->gateway.'_inc_tax' ],
            // $this->gateway . "_lc" => $_REQUEST[ $this->gateway.'_lc' ],
            $this->gateway . "_status" => $_REQUEST[ $this->gateway.'_status' ],
            $this->gateway . "_debug" => $_REQUEST[ $this->gateway.'_debug' ],
            // $this->gateway . "_format_logo" => $_REQUEST[ $this->gateway.'_format_logo' ],
            // $this->gateway . "_format_border" => $_REQUEST[ $this->gateway.'_format_border' ],
            $this->gateway . "_manual_approval" => $_REQUEST[ $this->gateway.'_manual_approval' ],
            $this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback' ]),
            $this->gateway . "_booking_feedback_free" => wp_kses_data( $_REQUEST[ $this->gateway.'_booking_feedback_free' ] ),
            $this->gateway . "_booking_feedback_thanks" => wp_kses_data( $_REQUEST[ $this->gateway.'_booking_feedback_thanks' ] ),
            $this->gateway . "_booking_timeout" => $_REQUEST[ $this->gateway.'_booking_timeout' ],
            $this->gateway . "_return" => $_REQUEST[ $this->gateway.'_return' ],
            $this->gateway . "_cancel_return" => $_REQUEST[ $this->gateway.'_cancel_return' ],
        );
        foreach ( $gateway_options as $key=>$option )
        {
            update_option( 'em_'.$key, stripslashes( $option ) );
        }
        // Default action is to return true
        return parent::update($gateway_options);
    }
}
EM_Gateways::register_gateway( 'payfast', 'EM_Gateway_PayFast' );
?>
