@extends('projekt.layout')

@section('title', 'Работна табла — Сметководство КРСТЕ')

@section('head')
<style>
  .top { position: sticky; top: 0; z-index: 5; background: var(--panel); border-bottom: 1px solid var(--line); }
  .top-in { display: flex; align-items: center; gap: 16px; padding: 10px 20px; }
  .brand { font-weight: 600; }
  .brand small { font-weight: 400; color: var(--muted); margin-left: 8px; }
  .temp { font-size: 12px; color: var(--warn); background: var(--warn-soft); border-radius: 999px; padding: 2px 10px; }
  .who { margin-left: auto; color: var(--muted); font-size: 14px; display: flex; gap: 12px; align-items: center; }

  .grid { display: grid; grid-template-columns: 200px minmax(0, 1fr) 440px; gap: 24px; padding: 20px; max-width: 1640px; margin: 0 auto; }
  .toc { position: sticky; top: 64px; align-self: start; font-size: 14px; }
  .toc a { display: block; padding: 5px 10px; color: var(--muted); text-decoration: none; border-left: 2px solid var(--line); }
  .toc a:hover { color: var(--ink); border-left-color: var(--accent); }
  .toc .label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 0 0 6px 12px; }

  .plan { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 8px 32px 28px; min-width: 0; }
  .plan h2 { font-size: 20px; margin: 28px 0 10px; padding-top: 8px; scroll-margin-top: 70px; }
  .plan h2:first-child { margin-top: 12px; }
  .plan p, .plan li { max-width: 72ch; }
  .plan blockquote { margin: 14px 0; padding: 8px 14px; border-left: 3px solid var(--warn); background: var(--warn-soft); color: #5a4300; border-radius: 0 6px 6px 0; }
  .plan blockquote p { margin: 4px 0; }
  .plan table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 14px; display: block; overflow-x: auto; }
  .plan th, .plan td { border: 1px solid var(--line); padding: 6px 10px; text-align: left; vertical-align: top; }
  .plan th { background: #f7f8fa; font-weight: 600; }
  .plan code { background: #f1f3f6; padding: 1px 5px; border-radius: 4px; font-size: 13px; }

  .side { position: sticky; top: 64px; align-self: start; max-height: calc(100vh - 80px); overflow-y: auto; padding-right: 4px; }
  .box { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; }
  .box > summary { cursor: pointer; font-weight: 600; list-style: none; }
  .box > summary::-webkit-details-marker { display: none; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 8px 0; }
  .form-row.one { grid-template-columns: 1fr; }
  .lbl { font-size: 12px; color: var(--muted); display: block; margin-bottom: 2px; }

  .col-head { display: flex; align-items: baseline; gap: 8px; margin: 18px 2px 8px; }
  .col-head h3 { margin: 0; font-size: 16px; }
  .col-head .people { color: var(--muted); font-size: 13px; }
  .col-head .count { margin-left: auto; font-size: 12px; color: var(--muted); }
  .col-owner h3 { color: var(--owner); }
  .col-accountant h3 { color: var(--accountant); }

  .item { background: var(--panel); border: 1px solid var(--line); border-left: 4px solid var(--line); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; scroll-margin-top: 70px; }
  .col-owner .item.open { border-left-color: var(--owner); }
  .col-accountant .item.open { border-left-color: var(--accountant); }
  .item.decided { border-left-color: var(--ok); }
  .item:target { box-shadow: 0 0 0 3px var(--accent-soft); }
  .item > summary { cursor: pointer; list-style: none; display: flex; gap: 6px; align-items: flex-start; }
  .item > summary::-webkit-details-marker { display: none; }
  .item .t { font-weight: 600; flex: 1; }
  .chips { display: flex; flex-wrap: wrap; gap: 4px; margin: 4px 0 6px; }
  .chip { font-size: 11px; border-radius: 999px; padding: 1px 8px; background: #eef0f3; color: var(--muted); white-space: nowrap; }
  .chip.done { background: var(--ok-soft); color: var(--ok); }
  .chip.new { background: var(--new); color: #fff; font-weight: 600; }
  .body { white-space: pre-line; font-size: 14px; color: #2c3644; margin: 6px 0; }
  .decision { background: var(--ok-soft); border: 1px solid #c7e6d2; border-radius: 6px; padding: 8px 10px; margin: 8px 0; font-size: 14px; }
  .decision .meta { font-size: 12px; color: var(--ok); margin-bottom: 2px; font-weight: 600; }
  .decision .txt { white-space: pre-line; }
  .comments { margin: 8px 0 4px; border-top: 1px dashed var(--line); padding-top: 6px; }
  .c { font-size: 14px; margin: 6px 0; }
  .c .meta { font-size: 12px; color: var(--muted); }
  .c .txt { white-space: pre-line; }
  .reply textarea { min-height: 54px; font-size: 14px; }
  .reply .acts { display: flex; gap: 6px; justify-content: flex-end; margin-top: 6px; flex-wrap: wrap; }
  .hint { font-size: 12px; color: var(--muted); }
  .toggle { font-size: 13px; color: var(--muted); display: flex; gap: 6px; align-items: center; margin: 4px 2px; }
  body.hide-decided .item.decided { display: none; }

  .tabs { display: flex; gap: 6px; margin: 4px 0 2px; position: sticky; top: 0; background: var(--bg); padding: 4px 0 8px; z-index: 2; }
  .tab { flex: 1; border: 1px solid var(--line); background: var(--panel); border-radius: 8px; padding: 7px 10px; cursor: pointer; text-align: left; }
  .tab b { display: block; font-size: 14px; }
  .tab span { font-size: 12px; color: var(--muted); }
  .tab[data-side=owner][aria-selected=true] { border-color: var(--owner); box-shadow: inset 0 -3px 0 var(--owner); }
  .tab[data-side=accountant][aria-selected=true] { border-color: var(--accountant); box-shadow: inset 0 -3px 0 var(--accountant); }
  .tab .n { background: var(--new); color: #fff; border-radius: 999px; padding: 0 6px; font-size: 11px; margin-left: 4px; }
  body.js-tabs .col-head { display: none; }
  body.js-tabs section[hidden] { display: none; }

  .jump { display: none; }
  @media (max-width: 1280px) { .grid { grid-template-columns: minmax(0, 1fr) 400px; } .toc { display: none; } }
  @media (max-width: 920px) {
    .grid { grid-template-columns: minmax(0, 1fr); padding: 12px 16px; }
    .side { position: static; max-height: none; overflow: visible; }
    .plan { padding: 4px 16px 20px; }
    .top-in { flex-wrap: wrap; padding: 8px 16px; gap: 8px; }
    .jump { display: inline-block; }
    /* Закачен наслов на телефон би ги покривал јазичињата — тие се закачуваат наместо него. */
    .top { position: static; }
    .item, .plan h2 { scroll-margin-top: 70px; }
  }
</style>
@endsection

@section('body')
<header class="top">
  <div class="top-in">
    <span class="brand">Сметководство КРСТЕ<small>работна табла на проектот</small></span>
    <span class="temp">привремена страница — се брише по завршувањето</span>
    <a class="btn small jump" href="#odluki">Прашања и одлуки ↓</a>
    <span class="who">
      {{ $user->name }}@if ($mySide) · {{ \App\Projekt\ProjectItem::SIDES[$mySide] }}@endif
      <form method="post" action="{{ route('logout') }}">@csrf<button class="btn ghost small" type="submit">Одјава</button></form>
    </span>
  </div>
</header>

<div class="grid">
  <nav class="toc" aria-label="Содржина">
    <p class="label">Планот</p>
    @foreach ($toc as $t)
      <a href="#{{ $t['id'] }}">{{ $t['title'] }}</a>
    @endforeach
  </nav>

  <main class="plan">
    {!! $planHtml !!}
  </main>

  <aside class="side" id="odluki" aria-label="Прашања и одлуки">
    @if (session('ok'))
      <div class="flash">{{ session('ok') }}</div>
    @endif
    @if ($errors->any())
      <div class="errors">{{ $errors->first() }}</div>
    @endif

    <details class="box" @if ($errors->has('title')) open @endif>
      <summary>+ Ново прашање или белешка</summary>
      <form method="post" action="{{ route('projekt.store') }}">
        @csrf
        <div class="form-row one">
          <label><span class="lbl">Наслов</span><input type="text" name="title" value="{{ old('title') }}" maxlength="200" required></label>
        </div>
        <div class="form-row one">
          <label><span class="lbl">Текст</span><textarea name="body" maxlength="5000">{{ old('body') }}</textarea></label>
        </div>
        <div class="form-row">
          <label><span class="lbl">Кој одлучува</span>
            <select name="side">
              @foreach (\App\Projekt\ProjectItem::SIDES as $k => $v)
                <option value="{{ $k }}" @selected(old('side', $mySide === 'owner' ? 'accountant' : 'owner') === $k)>{{ $v }}</option>
              @endforeach
            </select>
          </label>
          <label><span class="lbl">Вид</span>
            <select name="kind">
              @foreach (\App\Projekt\ProjectItem::KINDS as $k => $v)
                <option value="{{ $k }}" @selected(old('kind', 'question') === $k)>{{ $v }}</option>
              @endforeach
            </select>
          </label>
        </div>
        <div style="text-align:right"><button class="btn small" type="submit">Постави</button></div>
      </form>
    </details>

    @php
        // Својата страна прва — сметководителката ги гледа своите прашања, сопственикот своите.
        $order = $mySide === 'accountant' ? ['accountant', 'owner'] : ['owner', 'accountant'];
    @endphp
    <div class="tabs" role="tablist" hidden>
      @foreach ($order as $side)
        @php
          $list = $columns[$side];
          $newCount = $list->filter(fn ($i) => $isNew($i))->count();
        @endphp
        <button class="tab" type="button" role="tab" data-side="{{ $side }}" aria-selected="false">
          <b>{{ \App\Projekt\ProjectItem::SIDES[$side] }}@if ($newCount)<span class="n">{{ $newCount }} ново</span>@endif</b>
          <span>{{ $list->where('status', 'open')->count() }} отворени · {{ $list->where('status', 'decided')->count() }} одлучени</span>
        </button>
      @endforeach
    </div>

    <label class="toggle"><input type="checkbox" id="hide-decided"> Скриј ги одлучените</label>

    @foreach ($order as $side)
      @php
        $items = $columns[$side];
        $people = $users->isNotEmpty()
            ? $users->filter(fn ($u) => \App\Projekt\ProjektController::sideOf($u) === $side)->pluck('name')->implode(', ')
            : '';
        $openCount = $items->where('status', 'open')->count();
      @endphp
      <section class="col-{{ $side }}" data-side="{{ $side }}" role="tabpanel">
        <div class="col-head">
          <h3>{{ \App\Projekt\ProjectItem::SIDES[$side] }}</h3>
          @if ($people)<span class="people">{{ $people }}</span>@endif
          <span class="count">{{ $openCount }} отворени · {{ $items->count() - $openCount }} одлучени</span>
        </div>

        @forelse ($items as $item)
          <details class="item {{ $item->status }}" id="stavka-{{ $item->id }}" @if (! $item->isDecided()) open @endif>
            <summary>
              <span class="t">{{ $item->title }}</span>
            </summary>
            <div class="chips">
              @if ($isNew($item))<span class="chip new">НОВО</span>@endif
              @if ($item->isDecided())<span class="chip done">✓ одлучено</span>@endif
              <span class="chip">{{ \App\Projekt\ProjectItem::KINDS[$item->kind] ?? $item->kind }}</span>
              @if ($item->phase)<span class="chip">{{ $item->phase }}</span>@endif
              @if ($item->creator)<span class="chip">од {{ $item->creator->name }}</span>@endif
            </div>

            @if ($item->body)
              <div class="body">{{ $item->body }}</div>
            @endif

            @if ($item->isDecided())
              <div class="decision">
                <div class="meta">Одлука · {{ $item->deciderName() ?? '—' }} · {{ $item->decided_at?->format('d.m.Y') }}</div>
                <div class="txt">{{ $item->decision }}</div>
              </div>
            @endif

            @if ($item->comments->isNotEmpty())
              <div class="comments">
                @foreach ($item->comments as $c)
                  <div class="c">
                    <div class="meta">{{ $c->user?->name ?? '—' }} · {{ $c->created_at->format('d.m.Y H:i') }}</div>
                    <div class="txt">{{ $c->body }}</div>
                  </div>
                @endforeach
              </div>
            @endif

            <form class="reply" method="post" action="{{ route('projekt.comment', $item) }}">
              @csrf
              <textarea name="body" maxlength="5000" placeholder="{{ $item->isDecided() ? 'Коментар…' : 'Одговор, појаснување или прашање…' }}" aria-label="Текст"></textarea>
              <div class="acts">
                <button class="btn ghost small" type="submit">Коментирај</button>
                @if ($canDecide($item))
                  @if ($item->isDecided())
                    <button class="btn ghost small" type="submit" formaction="{{ route('projekt.reopen', $item) }}" formnovalidate>Отвори одново</button>
                  @else
                    <button class="btn ok small" type="submit" formaction="{{ route('projekt.decide', $item) }}">Одлучи</button>
                  @endif
                @endif
              </div>
              @if (! $item->isDecided() && $canDecide($item))
                <div class="hint">„Одлучи“ го запишува текстот како конечна одлука.</div>
              @endif
            </form>
          </details>
        @empty
          <p class="hint">Нема ставки.</p>
        @endforelse
      </section>
    @endforeach

    @if ($user->isSuper())
      <details class="box" id="korisnici" style="margin-top:20px">
        <summary>Пристап (само администратор)</summary>
        <p class="hint">Постоечки: {{ $users->map(fn ($u) => $u->name.' ('.(\App\Projekt\ProjectItem::SIDES[\App\Projekt\ProjektController::sideOf($u)] ?? 'само гледа').')')->implode(', ') }}</p>
        <form method="post" action="{{ route('projekt.users') }}">
          @csrf
          <div class="form-row">
            <label><span class="lbl">Име</span><input type="text" name="name" value="{{ old('name') }}" required></label>
            <label><span class="lbl">Е-пошта</span><input type="email" name="email" value="{{ old('email') }}" required></label>
          </div>
          <div class="form-row">
            <label><span class="lbl">Лозинка (мин. 10)</span><input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
            <label><span class="lbl">Страна</span>
              <select name="side">
                @foreach (\App\Projekt\ProjectItem::SIDES as $k => $v)
                  <option value="{{ $k }}" @selected($k === 'accountant')>{{ $v }}</option>
                @endforeach
              </select>
            </label>
          </div>
          <div style="text-align:right"><button class="btn small" type="submit">Создај пристап</button></div>
        </form>
      </details>
    @endif
  </aside>
</div>

<script>
  // Јазичиња меѓу двете страни. Без JS — двете се гледаат една под друга.
  (function () {
    var tabs = document.querySelectorAll('.tab');
    var panels = document.querySelectorAll('section[data-side]');
    var key = 'projekt-tab';
    function show(side) {
      tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.side === side ? 'true' : 'false'); });
      panels.forEach(function (p) { p.hidden = p.dataset.side !== side; });
      try { localStorage.setItem(key, side); } catch (e) {}
    }
    document.querySelector('.tabs').hidden = false;
    document.body.classList.add('js-tabs');
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.side); }); });
    // Врска кон ставка (#stavka-N, по зачувување) ја отвора нејзината страна.
    var target = location.hash && document.querySelector(location.hash);
    var saved = null;
    try { saved = localStorage.getItem(key); } catch (e) {}
    var side = target && target.closest('section[data-side]') ? target.closest('section[data-side]').dataset.side
             : (saved || tabs[0].dataset.side);
    show(side);
    if (target) { target.scrollIntoView({ block: 'start' }); }
  })();

  // Изборот „скриј ги одлучените“ се памети во овој прелистувач.
  (function () {
    var box = document.getElementById('hide-decided');
    var key = 'projekt-hide-decided';
    try { box.checked = localStorage.getItem(key) === '1'; } catch (e) {}
    function apply() { document.body.classList.toggle('hide-decided', box.checked); }
    box.addEventListener('change', function () {
      apply();
      try { localStorage.setItem(key, box.checked ? '1' : '0'); } catch (e) {}
    });
    apply();
  })();
</script>
@endsection
