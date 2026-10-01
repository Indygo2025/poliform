<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$d     = pl_boot();
$base  = 'https://poliform.pages.dev/';

pl_head($d, [
    'title' => 'Корзина и заявка — Полиформ',
    'description' => 'Корзина заказа пластиковых изделий: оптовая часть кратна боксу, розничная — поштучно.',
    'base'  => $base,
]);
pl_header($d, 'cart');
pl_breadcrumbs_jsonld($base, ['Главная' => 'index.php', 'Корзина и заявка' => 'cart.php']);
?>

<section>
  <div class="container">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <a href="index.php">Главная</a> / <span>Корзина и заявка</span>
    </nav>

    <div class="sec-head" style="margin-bottom:26px">
      <div>
        <span class="eyebrow">Заказ</span>
        <h1 class="sec-title" style="font-size:clamp(1.8rem,4vw,2.6rem)">Корзина <em>и заявка</em></h1>
        <p class="sec-sub">В заказе можно смешивать опт и розницу: оптовая часть считается кратно боксу, розничная — поштучно. Стоимость доставки менеджер рассчитает отдельно.</p>
      </div>
    </div>

    <div class="cart-layout">
      <div>
        <div data-cart></div>

        <div class="block block--tint" id="order" data-order-form style="margin-top:30px">
          <h2 style="margin-top:0">Данные для заявки</h2>
          <form id="order-form" novalidate>
            <input type="text" name="website" value="" style="position:absolute;left:-9999px;opacity:0;pointer-events:none" tabindex="-1" autocomplete="off">
            <div class="form-grid">
              <div class="form-row">
                <div class="field">
                  <label for="of-name">Имя <span class="req">*</span></label>
                  <input id="of-name" name="name" type="text" autocomplete="name" placeholder="Иван Петров" required>
                  <span class="err-msg">Укажите имя</span>
                </div>
                <div class="field">
                  <label for="of-phone">Телефон <span class="req">*</span></label>
                  <input id="of-phone" name="phone" type="tel" autocomplete="tel" placeholder="+7 (900) 000-00-00" required>
                  <span class="err-msg">Укажите телефон для связи</span>
                </div>
              </div>
              <div class="form-row">
                <div class="field">
                  <label for="of-email">E-mail</label>
                  <input id="of-email" name="email" type="email" autocomplete="email" placeholder="mail@example.ru">
                </div>
                <div class="field">
                  <label for="of-city">Город / регион</label>
                  <input id="of-city" name="city" type="text" autocomplete="address-level2" placeholder="Смоленск">
                </div>
              </div>
              <div class="field">
                <label for="of-comment">Комментарий к заказу</label>
                <textarea id="of-comment" name="comment" placeholder="Например: нужна отгрузка одной машиной, интересует срок производства"></textarea>
              </div>

              <div class="field">
                <label for="of-items">Состав заказа (заполняется автоматически)</label>
                <textarea id="of-items" name="items" data-order-items readonly rows="4" placeholder="Позиции появятся после добавления в корзину"></textarea>
              </div>

              <label class="checkbox">
                <input type="checkbox" name="agree" checked>
                <span>Согласен на обработку персональных данных для ответа по заявке</span>
              </label>

              <div class="ppb__actions" style="margin-top:4px">
                <button class="btn btn-signal" type="submit">Собрать заявку</button>
                <a class="btn btn-ghost" data-telegram-link href="<?= e((string)($d['contacts']['telegram'] ?? 'https://t.me/poliform')) ?>" rel="nofollow noopener" target="_blank">Отправить в Telegram</a>
              </div>
              <p class="sum-note" style="margin-top:0">Форма не сохраняет данные на сервере: заявка собирается в текст, который вы отправляете удобным способом — в Telegram или по телефону.</p>
              <div class="form-msg" data-form-msg role="status"></div>
              <div class="field">
                <label for="order-text">Текст заявки (для копирования)</label>
                <textarea id="order-text" data-order-text readonly rows="8" style="display:none"></textarea>
              </div>
            </div>
          </form>
        </div>
      </div>

      <aside class="cart-summary" data-cart-summary>
        <h3>Итог по заказу</h3>
        <div class="sum-note">Добавьте позиции из каталога.</div>
      </aside>
    </div>
  </div>
</section>

<?php
pl_footer($d);
