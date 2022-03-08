<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Midtrans\Snap;
use App\Transaction;
use Midtrans\Config;
use App\TravelPackage;

use App\TransactionDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function index(Request $request, $id)
    {
        $item = Transaction::with(['details','travel_package','user'])->findOrFail($id);

        return view('pages.checkout',[
            'item' => $item
        ]);
    }

    public function process(Request $request, $id)
    {
        $travel_package = TravelPackage::findOrFail($id);

        $transaction = Transaction::create([
            'travel_packages_id' => $id,
            'users_id' => Auth::user()->id,
            'additional_visa' => 0,
            'transaction_total' => $travel_package->price,
            'transaction_status' => 'IN_CART'
        ]);

        TransactionDetail::create([
            'transactions_id' => $transaction->id,
            'username' => Auth::user()->username,
            'nationality' => 'ID',
            'is_visa' => false,
            'doe_passport' => Carbon::now()->addYears(5)
        ]);

        return redirect()->route('checkout', $transaction->id);
    }

    public function remove(Request $request, $detail_id)
    {
        $item = TransactionDetail::findorFail($detail_id);

        $transaction = Transaction::with(['details','travel_package'])
            ->findOrFail($item->transactions_id);

        if($item->is_visa)
        {
            $transaction->transaction_total -= 190;
            $transaction->additional_visa -= 190;
        }

        $transaction->transaction_total -= $transaction->travel_package->price;

        $transaction->save();
        $item->delete();

        return redirect()->route('checkout', $item->transactions_id);
    }

    public function create(Request $request, $id)
    {
        $request->validate([
            'username' => 'required|string|exists:users,username',
            'is_visa' => 'required|boolean',
            'doe_passport' => 'required',
        ]);

        $data = $request->all();
        $data['transactions_id'] = $id;

        TransactionDetail::create($data);

        $transaction = Transaction::with(['travel_package'])->find($id);

        if($request->is_visa)
        {
            $transaction->transaction_total += 190;
            $transaction->additional_visa += 190;
        }

        $transaction->transaction_total += $transaction->travel_package->price;

        $transaction->save();

        return redirect()->route('checkout', $id);
    }

    public function success(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->transaction_status = 'PENDING';

        $transaction->save();

        // Konfigurasi Awal
        # Integrasi Payment gateway duitku
        $duitkuConfig = new \Duitku\Config("5193c71713b666489a17a30f8677cc2d", "D11184");
        # // false for production mode
        # // true for sandbox mode
        $duitkuConfig->setSandboxMode(true);
        # // set sanitizer (default : true)
        $duitkuConfig->setSanitizedMode(true);
        # // set log parameter (default : true)
        $duitkuConfig->setDuitkuLogs(false);
        
        $paymentAmount      = $transaction->transaction_total; // Amount
        $email              = $transaction->user->email; // your customer email
        $productDetails     = "Test Payment";
        $callbackUrl        = 'http://nomads.test/callback/payment'; // url for callback
        $returnUrl          = 'http://nomads.test/callback/return'; // url for redirect
        $phoneNumber        = "081379117428"; // your customer phone number (optional)
        $productDetails     = "Test Payment";
        $merchantOrderId    = time(); // from merchant, unique   

        // Customer Detail
        $firstName          = $transaction->user->name;

        // Address
        $alamat             = "Jl. Kirap Remaja";
        $city               = "Muara Enim";
        $postalCode         = "31315";
        $countryCode        = "ID";

        $address = array(
            'firstName'     => $firstName,
            'address'       => $alamat,
            'city'          => $city,
            'postalCode'    => $postalCode,
            'phone'         => $phoneNumber,
            'countryCode'   => $countryCode
        );

        $customerDetail = array(
            'firstName'         => $firstName,
            'email'             => $email,
            'phoneNumber'       => $phoneNumber,
            'billingAddress'    => $address,
            'shippingAddress'   => $address
        );

        // Item Details
        $item1 = array(
            'name'      => $productDetails,
            'price'     => $paymentAmount,
            'quantity'  => 1
        );

        $itemDetails = array(
            $item1
        );

        $params = array(
            'paymentAmount'     => $paymentAmount,
            'merchantOrderId'   => $merchantOrderId,
            'productDetails'    => $productDetails,
            'email'             => $email,
            'phoneNumber'       => $phoneNumber,
            'itemDetails'       => $itemDetails,
            'customerDetail'    => $customerDetail,
            'callbackUrl'       => $callbackUrl,
            'returnUrl'         => $returnUrl,
        );

        try {
            // createInvoice Request
            $responseDuitkuPop = \Duitku\Pop::createInvoice($params, $duitkuConfig);

            header('Content-Type: application/json');
            echo $responseDuitkuPop;
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        // // Set Konfigurasi Midtrans
        // Config::$serverKey      = config('midtrans.serverKey');
        // Config::$isProduction   = config('midtrans.isProduction');
        // Config::$isSanitized    = config('midtrans.isSanitized');
        // Config::$is3ds          = config('midtrans.is3ds');

        // // Buat array untuk dikirim ke midtrans
        // $midtrans_params = [
        //     'transaction_details' => [
        //         'order_id'      => 'TEST-' . $transaction->id,
        //         'gross_amount'  => (int) $transaction->transaction_total
        //     ],
        //     'customer_details' => [
        //         'first_name'    => $transaction->user->name,
        //         'email'         => $transaction->user->email,
        //     ],
        //     'enabled_payments' => ['gopay'],
        //     'vtweb' => []
        // ];

        // try {
        //     // Ambil halaman payment midtrans
        //     $paymentUrl = Snap::createTransaction($midtrans_params)->redirect_url;

        //     // Redirect ke halaman midtrans
        //     header('Location: ' . $paymentUrl);

        // } catch (Exception $e) {
        //     echo $e->getMessage();
        // }

        // return view('pages.success');
    }   
}
