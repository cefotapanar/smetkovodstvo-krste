@extends('projekt.layout')

@section('title', 'Најава — Сметководство КРСТЕ')

@section('head')
<style>
  .wrap { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
  .card { width: 100%; max-width: 380px; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 28px 26px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
  label { display: block; font-size: 13px; color: var(--muted); margin: 12px 0 4px; }
  .row { display: flex; align-items: center; justify-content: space-between; margin-top: 18px; }
</style>
@endsection

@section('body')
<div class="wrap">
  <form class="card" method="post" action="{{ url('/login') }}">
    @csrf
    <h1>Сметководство КРСТЕ</h1>
    <p class="sub">Работна табла на проектот — план, прашања и одлуки.</p>

    @if ($errors->any())
      <div class="errors">{{ $errors->first() }}</div>
    @endif

    <label for="email">Е-пошта</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

    <label for="password">Лозинка</label>
    <input id="password" type="password" name="password" required autocomplete="current-password">

    <div class="row">
      <span class="sub" style="margin:0">Пристап само со покана.</span>
      <button class="btn" type="submit">Влез</button>
    </div>
  </form>
</div>
@endsection
