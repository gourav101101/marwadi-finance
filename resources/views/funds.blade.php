@extends('layouts.app')
@section('title','Add funds')
@section('heading','Add funds')
@section('description','Your next investment starts here.')
@section('content')
<div class="payment-grid">
	<section class="card payment-card">
		<span class="payment-icon"><x-icon name="qr" :size="27"/></span>
		<h2>Scan. Pay. Invest.</h2>
		<p>Use your preferred UPI app to scan the QR code.</p>
		<div class="qr-frame">
			@if($qr)
				<img src="{{ route('documents.show',$qr) }}" alt="Payment QR code">
			@else
				<div class="qr-empty"><x-icon name="qr" :size="75"/><span>Payment QR not available yet</span></div>
			@endif
		</div>
		@if(filled($workspace->payment_name) || filled($workspace->bank_account_number) || filled($workspace->ifsc_code) || filled($workspace->upi_id))
			<dl class="payment-details" aria-label="Payment details">
				@if(filled($workspace->payment_name))<div><dt>Account holder name</dt><dd>{{ $workspace->payment_name }}</dd></div>@endif
				@if(filled($workspace->bank_account_number))<div><dt>Bank account number</dt><dd>{{ $workspace->bank_account_number }}</dd></div>@endif
				@if(filled($workspace->ifsc_code))<div><dt>IFSC code</dt><dd>{{ $workspace->ifsc_code }}</dd></div>@endif
				@if(filled($workspace->upi_id))<div><dt>UPI ID</dt><dd><button type="button" class="copy-upi" data-copy="{{ $workspace->upi_id }}">{{ $workspace->upi_id }} <x-icon name="file" :size="14"/></button></dd></div>@endif
			</dl>
		@endif
		<small>Confirm the recipient’s details before paying.</small>
	</section>
	<div>
		<section class="card balance-card">
			<span class="eyebrow">YOUR AVAILABLE FUNDS</span>
			<h2>{{ \App\Support\Format::money($balances->sum('available')) }}</h2>
			<p>Ready for your next move.</p>
			<div><span>Portfolio balance</span><strong>{{ \App\Support\Format::money($balances->sum('balance')) }}</strong></div>
			<div><span>Reserved for withdrawals</span><strong>{{ \App\Support\Format::money($balances->sum('pending')) }}</strong></div>
		</section>
		<section class="card funding-instructions">
			<h2>A few things before you pay</h2>
			<ol>
				<li><span>01</span><div><strong>Check the payee details</strong><p>Confirm the recipient in your UPI app.</p></div></li>
				<li><span>02</span><div><strong>Add your Client ID</strong><p>Use <b>{{ $selected?->code ?? 'your Client ID' }}</b> in the payment reference.</p></div></li>
				<li><span>03</span><div><strong>Share the payment reference</strong><p>Your advisor will verify payment and record your deposit.</p></div></li>
			</ol>
			@if(filled($workspace->payment_note))<div class="info-box">{{ $workspace->payment_note }}</div>@endif
		</section>
	</div>
</div>
@endsection
