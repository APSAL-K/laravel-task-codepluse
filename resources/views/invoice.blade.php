<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice {{ $invoice['invoice_number'] }}</title>
<style>
  * { box-sizing:border-box; }
  body { margin:0; background:#eef1f6; color:#0f172a; font:14px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif; }
  .bar { max-width:820px; margin:20px auto 0; padding:0 16px; display:flex; justify-content:flex-end; gap:8px; }
  .bar button, .bar a { border:1px solid #cbd5e1; background:#fff; border-radius:8px; padding:8px 14px; font:inherit; font-weight:600; cursor:pointer; color:#0f172a; text-decoration:none; }
  .bar button.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
  .sheet { max-width:820px; margin:16px auto 40px; background:#fff; border-radius:14px; padding:44px; box-shadow:0 10px 40px rgba(15,23,42,.1); position:relative; overflow:hidden; }
  .paid { position:absolute; top:34px; right:-38px; transform:rotate(35deg); background:#15803d; color:#fff; font-weight:800; letter-spacing:.2em; padding:6px 52px; font-size:12px; }
  header { display:flex; justify-content:space-between; gap:24px; margin-bottom:32px; flex-wrap:wrap; }
  .brand { font-size:22px; font-weight:800; letter-spacing:-.02em; }
  .muted { color:#64748b; }
  h1 { margin:0; font-size:28px; letter-spacing:-.02em; }
  .meta { text-align:right; }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:28px; }
  h3 { margin:0 0 6px; font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
  table { width:100%; border-collapse:collapse; }
  th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748b; padding:10px 8px; border-bottom:2px solid #e2e8f0; }
  td { padding:12px 8px; border-bottom:1px solid #eef1f6; }
  .r { text-align:right; }
  .total td { border-bottom:0; font-size:18px; font-weight:800; padding-top:16px; }
  .note { margin-top:28px; padding:14px 16px; background:#f6f8fb; border-radius:10px; font-size:13px; }
  footer { margin-top:32px; text-align:center; color:#64748b; font-size:12px; }
  @media print { body { background:#fff; } .bar { display:none; } .sheet { box-shadow:none; margin:0; max-width:none; border-radius:0; } }
  @media (max-width:600px) { .sheet { padding:24px; } .grid { grid-template-columns:1fr; } .meta { text-align:left; } }
</style>
</head>
<body>
@php
  $money = fn ($paise) => '₹'.number_format($paise / 100, 2);
  $inv = $invoice;
@endphp
<div class="bar"><a href="/#/orders">Back to orders</a><button class="primary" onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
  <div class="paid">PAID</div>
  <header>
    <div>
      <div class="brand">{{ $inv['seller']['name'] }}</div>
      <div class="muted">{{ $inv['seller']['address'] }}</div>
      @if($inv['seller']['gstin'])<div class="muted">GSTIN: {{ $inv['seller']['gstin'] }}</div>@endif
      <div class="muted">{{ $inv['seller']['email'] }}</div>
    </div>
    <div class="meta">
      <h1>Invoice</h1>
      <div><b>{{ $inv['invoice_number'] }}</b></div>
      <div class="muted">Date: {{ \Illuminate\Support\Carbon::parse($inv['invoice_date'])->timezone(config('app.timezone'))->format('d M Y, h:i A') }}</div>
      <div class="muted">Order: {{ $inv['order_number'] }}</div>
    </div>
  </header>

  <div class="grid">
    <div>
      <h3>Billed to</h3>
      @if($inv['customer']['name'] || $inv['customer']['email'] || $inv['customer']['phone'])
        <div><b>{{ $inv['customer']['name'] ?: 'Customer' }}</b></div>
        @if($inv['customer']['email'])<div class="muted">{{ $inv['customer']['email'] }}</div>@endif
        @if($inv['customer']['phone'])<div class="muted">{{ $inv['customer']['phone'] }}</div>@endif
      @else
        <div class="muted">Walk-in customer</div>
      @endif
    </div>
    <div>
      <h3>Payment</h3>
      <div><b>Paid via Razorpay</b></div>
      @if($inv['payment']['method'])
        <div class="muted">{{ strtoupper($inv['payment']['method']) }}@if($inv['payment']['method_detail']) · {{ $inv['payment']['method_detail'] }}@endif</div>
      @endif
      <div class="muted">Ref: {{ $inv['payment']['razorpay_payment_id'] }}</div>
    </div>
  </div>

  <table>
    <thead><tr><th>#</th><th>Item</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead>
    <tbody>
    @foreach($inv['items'] as $i => $item)
      <tr><td class="muted">{{ $i + 1 }}</td><td>{{ $item['name'] }}</td><td class="r">{{ $item['quantity'] }}</td><td class="r">{{ $money($item['unit_price']) }}</td><td class="r">{{ $money($item['subtotal']) }}</td></tr>
    @endforeach
      <tr class="total"><td colspan="4" class="r">Total paid</td><td class="r">{{ $money($inv['total']) }}</td></tr>
    </tbody>
  </table>

  <div class="note">All prices are in {{ $inv['currency'] }} and inclusive of applicable taxes. This is a computer-generated invoice and needs no signature.</div>
  <footer>Thank you for your purchase.</footer>
</div>
</body>
</html>
