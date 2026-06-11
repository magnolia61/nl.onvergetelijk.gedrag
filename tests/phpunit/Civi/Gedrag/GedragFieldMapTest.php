<?php

namespace Civi\Gedrag;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor gedrag_get_field_map() in nl.onvergetelijk.gedrag.
 *
 * @group e2e
 *
 * gedrag_get_field_map() koppelt database-kolomnamen aan GEDRAG.*-sleutels.
 *
 * Scenario's:
 *   - Retourneert een non-lege array
 *   - Alle sleutels bevatten een numeriek suffix
 *   - Alle waarden beginnen met 'GEDRAG.'
 *   - Bevat verplichte velden: gedrag_issues, gedrag_check, gedrag_modified
 *   - Alle waarden zijn uniek
 */
class GedragFieldMapTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('gedrag_get_field_map')) {
      $this->markTestSkipped('gedrag_get_field_map() niet beschikbaar; is nl.onvergetelijk.gedrag geïnstalleerd?');
    }
  }

  public function testMapIsNonLeegArray() {
    $this->assertNotEmpty(gedrag_get_field_map());
  }

  public function testSleutelsHebbenNumeriekeId() {
    foreach (gedrag_get_field_map() as $key => $value) {
      $this->assertMatchesRegularExpression('/_\d+$/', $key, "Sleutel '$key' moet eindigen op numeriek suffix.");
    }
  }

  public function testWaardenBeginnenMetGedrag() {
    foreach (gedrag_get_field_map() as $key => $value) {
      $this->assertStringStartsWith('GEDRAG.', $value, "Waarde '$value' moet beginnen met 'GEDRAG.'.");
    }
  }

  public function testBevatVerplichteFelden() {
    $values = array_values(gedrag_get_field_map());
    $this->assertContains('GEDRAG.gedrag_issues',   $values);
    $this->assertContains('GEDRAG.gedrag_check',    $values);
    $this->assertContains('GEDRAG.gedrag_modified', $values);
  }

  public function testWaardenZijnUniek() {
    $values = array_values(gedrag_get_field_map());
    $this->assertEquals(count($values), count(array_unique($values)));
  }
}
