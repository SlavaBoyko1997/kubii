@extends('layouts.store')

@section('title', __('Кошик'))

@section('content')
    <div class="container page-space">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> / <span>{{ __('Кошик') }}</span></div>
        <h1>{{ __('Ваш кошик') }}</h1>
        <div data-cart-page>@include('store._cart-content')</div>
    </div>
@endsection
