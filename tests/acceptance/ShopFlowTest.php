<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * End-to-end flow of the generic checkout fixture (level-shop): a complete
 * four-step process with derived data, auxiliary handlers, deep-links,
 * conditional skips, render guards and a keepData final step.
 */
class ShopFlowTest extends BaseTestCase
{
    public function test_the_whole_happy_path_places_the_order()
    {
        // Step 1: store selection.
        $this->get('/level-shop/branch')->assertOk();
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-shop/items'));

        // Step 2: quantities; the derived totals ride along.
        $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
            'quantity_widget' => '2',
            'quantity_gadget' => '1',
        ])->assertOk()->assertJsonPath('X_WINTER_REDIRECT', url('/level-shop/buyer'));

        // Step 3: buyer details.
        $this->ajaxRequest('/level-shop/buyer', 'wizard::onNext', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ])->assertOk()->assertJsonPath('X_WINTER_REDIRECT', url('/level-shop/review'));

        // Step 4: review and pay. The last step completes the wizard.
        $this->ajaxRequest('/level-shop/review', 'wizard::onNext', [
            'payment_method' => 'card',
            'terms' => '1',
        ])->assertOk()->assertJsonPath('X_WINTER_REDIRECT', url('/level-shop-finish'));

        // The commit hook placed the order exactly once, with everything.
        $order = session('shop_last_order');
        $this->assertEquals(45, $order['totals']['subtotal']);
        $this->assertEquals(['is_known' => false], $order['buyer']);
        $this->assertEquals('card', $order['payment']);
    }

    public function test_the_cart_handler_returns_the_derived_data()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);
        $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
            'quantity_widget' => '2',
            'quantity_gadget' => '1',
        ]);

        $cart = $this->ajaxRequest('/level-shop/items', 'onCartData')->json();

        $this->assertEquals(45, $cart['cart']['subtotal']);
        $this->assertEquals('Central store', $cart['store']);
    }

    public function test_the_cart_handler_guard_sends_back_when_the_cart_is_empty()
    {
        $redirect = $this->ajaxRequest('/level-shop/items', 'onCartData')->json('X_WINTER_REDIRECT');

        $this->assertStringContainsString('/level-shop/branch', $redirect);
    }

    public function test_the_remove_item_handler_merges_into_the_session()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);
        $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
            'quantity_widget' => '3',
            'quantity_gadget' => '1',
        ]);

        $cart = $this->ajaxRequest('/level-shop/items', 'onRemoveItem', ['product' => 'widget'])->json();

        $this->assertEquals(2, $cart['cart']['items']['widget']['quantity']);
        $this->assertEquals(45, $cart['cart']['subtotal']); // 2 widgets + 1 gadget

        // The RAW quantity is what persists: it is the source of truth the
        // derived totals are rebuilt from.
        $state = session($this->wizardSessionKey('level-shop'));
        $this->assertEquals('2', $state['data']['items']['quantity_widget']);
        $this->assertEquals(2, $state['data']['items']['totals']['items']['widget']['quantity']);

        // A full reload reads the persisted state back.
        $this->get('/level-shop/buyer')->assertOk();

        $state = session($this->wizardSessionKey('level-shop'));
        $this->assertEquals('2', $state['data']['items']['quantity_widget']);
        $this->assertEquals(2, $state['data']['items']['totals']['items']['widget']['quantity']);
    }

    public function test_the_remove_item_handler_sends_back_when_the_cart_empties()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);
        $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
            'quantity_widget' => '1',
        ]);

        $redirect = $this->ajaxRequest('/level-shop/items', 'onRemoveItem', ['product' => 'widget'])
            ->json('X_WINTER_REDIRECT');

        $this->assertStringContainsString('/level-shop/branch', $redirect);
    }

    public function test_the_locked_account_lookup_blocks_the_step()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);
        $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
            'quantity_widget' => '1',
            'quantity_gadget' => '1',
        ]);

        $this->assertAjaxException(
            $this->ajaxRequest('/level-shop/buyer', 'wizard::onNext', [
                'name' => 'Jane Doe',
                'email' => 'locked@shop.test',
            ]),
            ['email' => ['This account is locked. Contact support.']]
        );
    }

    public function test_the_control_channel_rides_on_the_error_fields()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);
        $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
            'quantity_widget' => '1',
            'quantity_gadget' => '1',
        ]);

        // A failing step with a KNOWN buyer also carries the control field.
        $this->assertAjaxException(
            $this->ajaxRequest('/level-shop/buyer', 'wizard::onNext', [
                'email' => 'known@shop.test',
            ]),
            [
                'name' => ['The name field is required.'],
                '_known_buyer' => [1],
            ]
        );
    }

    public function test_a_deep_link_prefills_the_store_and_unlocks_the_next_step()
    {
        $this->get('/level-shop/items?store=center')->assertOk();

        $this->get('/level-shop/branch')
            ->assertOk()
            ->assertSee('value="center"', false);
    }

    public function test_the_root_url_carries_the_entry_query_into_the_first_step()
    {
        // Deep-link campaigns enter through the wizard root URL: the internal
        // first-step redirect must preserve the entry parameters, because the
        // page's onInit pattern reads them after the bounce.
        $this->get('/level-shop?store=center')
            ->assertRedirect('/level-shop/branch?store=center');

        $this->get('/level-shop?store=center&express=1&product=gadget')
            ->assertRedirect('/level-shop/branch?express=1&product=gadget&store=center');
    }

    public function test_an_express_deep_link_jumps_to_the_buyer_step()
    {
        $this->get('/level-shop/buyer?store=center&express=1&product=gadget')->assertOk();

        // The express cart is already computed: the buyer submit advances
        // to the review step, and the review render guard finds the totals.
        $this->ajaxRequest('/level-shop/buyer', 'wizard::onNext', [
            'name' => 'Express Buyer',
            'email' => 'express@shop.test',
        ])->assertJsonPath('X_WINTER_REDIRECT', url('/level-shop/review'));

        $this->get('/level-shop/review')->assertOk();

        // The preselected product is in the items step. Going back here
        // wipes the data of the later steps first, not the items prefill.
        $this->get('/level-shop/items')
            ->assertOk()
            ->assertSee('value="1"', false);

        // Branch is prefilled as well; going back to branch wipes the items
        // data (privacy first) and review becomes unreachable again.
        $this->get('/level-shop/branch')
            ->assertOk()
            ->assertSee('value="center"', false);
        $this->get('/level-shop/review')->assertRedirect();
    }

    public function test_the_express_handler_skips_to_the_review_step()
    {
        $redirect = $this->ajaxRequest('/level-shop/branch?express=1&product=gadget', 'wizard::onNext', ['store' => 'center'])
            ->json('X_WINTER_REDIRECT');

        $this->assertStringContainsString('/level-shop/review', $redirect);
        $this->assertStringContainsString('express=1', $redirect);

        // The handler computes the cart for the express product: the review
        // render guard is satisfied, so the express user lands on the
        // payment review and completes the flow from there.
        $this->get('/level-shop/review?express=1')->assertOk();

        $this->ajaxRequest('/level-shop/review', 'wizard::onNext', [
            'payment_method' => 'card',
            'terms' => '1',
        ])->assertJsonPath('X_WINTER_REDIRECT', url('/level-shop-finish'));
    }

    public function test_the_render_guard_bounces_an_empty_cart_from_the_review()
    {
        $this->get('/level-shop/review')->assertRedirect();
    }
}
