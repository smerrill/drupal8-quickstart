<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\d6\MigrateTaxonomyVocabularyTest.
 */

namespace Drupal\migrate_drupal\Tests\d6;

use Drupal\migrate\MigrateExecutable;
use Drupal\migrate_drupal\Tests\MigrateDrupalTestBase;

/**
 * Tests the Drupal 6 taxonomy vocabularies to Drupal 8 migration.
 */
class MigrateTaxonomyVocabularyTest extends MigrateDrupalTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('taxonomy');

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name'  => 'Migrate taxonomy vocabularies.',
      'description'  => 'Migrate taxonomy vocabularies to taxonomy.vocabulary.*.yml',
      'group' => 'Migrate Drupal',
    );
  }


  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $migration = entity_load('migration', 'd6_taxonomy_vocabulary');
    $dumps = array(
      $this->getDumpDirectory() . '/Drupal6TaxonomyVocabulary.php',
    );
    $this->prepare($migration, $dumps);
    $executable = new MigrateExecutable($migration, $this);
    $executable->import();
  }

  /**
   * Tests the Drupal 6 taxonomy vocabularies to Drupal 8 migration.
   */
  public function testTaxonomyVocabulary() {
    for ($i = 0; $i < 3; $i++) {
      $j = $i + 1;
      $vocabulary = entity_load('taxonomy_vocabulary', "vocabulary_{$j}_i_{$i}_");
      $this->assertEqual(array($vocabulary->id()), entity_load('migration', 'd6_taxonomy_vocabulary')->getIdMap()->lookupDestinationID(array($j)));
      $this->assertEqual($vocabulary->name, "vocabulary $j (i=$i)");
      $this->assertEqual($vocabulary->description, "description of vocabulary $j (i=$i)");
      $this->assertEqual($vocabulary->hierarchy, $i);
      $this->assertEqual($vocabulary->weight, 4   + $i);
    }
  }

}
