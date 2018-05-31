<?php

namespace TractorCow\Fluent\Tests\Extension;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use TractorCow\Fluent\Extension\FluentSiteTreeExtension;
use TractorCow\Fluent\State\FluentState;
use TractorCow\Fluent\Tests\Extension\FluentExtensionTest\LocalisedAnother;
use TractorCow\Fluent\Tests\Extension\FluentExtensionTest\LocalisedChild;
use TractorCow\Fluent\Tests\Extension\FluentExtensionTest\LocalisedParent;
use TractorCow\Fluent\Tests\Extension\FluentExtensionTest\UnlocalisedChild;
use TractorCow\Fluent\Tests\Extension\Stub\FluentStubObject;

class FluentExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'FluentExtensionTest.yml';

    protected static $extra_dataobjects = [
        LocalisedAnother::class,
        LocalisedChild::class,
        LocalisedParent::class,
        UnlocalisedChild::class,
    ];

    protected static $required_extensions = [
        SiteTree::class => [
            FluentSiteTreeExtension::class,
        ],
    ];

    public function testFluentLocaleAndFrontendAreAddedToDataQuery()
    {
        FluentState::singleton()
            ->setLocale('test')
            ->setIsFrontend(true);

        $query = SiteTree::get()->dataQuery();
        $this->assertSame('test', $query->getQueryParam('Fluent.Locale'));
        $this->assertTrue($query->getQueryParam('Fluent.IsFrontend'));
    }

    public function testGetLocalisedTable()
    {
        /** @var SiteTree|FluentSiteTreeExtension $page */
        $page = new SiteTree;
        $this->assertSame('SiteTree_Localised', $page->getLocalisedTable('SiteTree'));
        $this->assertSame(
            'SiteTree_Localised_FR',
            $page->getLocalisedTable('SiteTree', 'FR'),
            'Table aliases can be generated with getLocalisedTable()'
        );
    }

    public function testGetLinkingMode()
    {
        // Does not have a canViewInLocale method, locale is not current
        $stub = new FluentStubObject();
        $this->assertSame('link', $stub->getLinkingMode('foo'));

        // Does not have a canViewInLocale method, locale is current
        FluentState::singleton()->setLocale('foo');
        $this->assertSame('current', $stub->getLinkingMode('foo'));
    }

    public function testGetLocalisedFields()
    {
        // test data_include / data_exclude
        // note: These parent fields should be all accessible from the child records as well
        $parent = new LocalisedParent();
        $parentLocalised = [
            'Title' => 'Varchar',
            'Details' => 'Varchar(200)',
        ];
        $this->assertEquals(
            $parentLocalised,
            $parent->getLocalisedFields()
        );

        // test field_include / field_exclude
        $another = new LocalisedAnother();
        $this->assertEquals(
            [
                'Bastion' => 'Varchar',
                'Data' => 'Varchar(100)',
            ],
            $another->getLocalisedFields()
        );
        $this->assertEquals(
            $parentLocalised,
            $another->getLocalisedFields(LocalisedParent::class)
        );

        // Test translate directly
        $child = new LocalisedChild();
        $this->assertEquals(
            [ 'Record' => 'Text' ],
            $child->getLocalisedFields()
        );
        $this->assertEquals(
            $parentLocalised,
            $child->getLocalisedFields(LocalisedParent::class)
        );

        // Test 'none'
        $unlocalised = new UnlocalisedChild();
        $this->assertEmpty($unlocalised->getLocalisedFields());
        $this->assertEquals(
            $parentLocalised,
            $unlocalised->getLocalisedFields(LocalisedParent::class)
        );
    }

    public function testWritesToCurrentLocale()
    {
        FluentState::singleton()->setLocale('en_US');
        $record = $this->objFromFixture(LocalisedParent::class, 'record_a');
        $this->assertTrue(
            $this->hasLocalisedRecord($record, 'en_US'),
            'Record can be read from default locale'
        );

        FluentState::singleton()->setLocale('de_DE');
        $record2 = $this->objFromFixture(LocalisedParent::class, 'record_a');
        $this->assertTrue(
            $this->hasLocalisedRecord($record2, 'de_DE'),
            'Existing record can be read from German locale'
        );

        // There's no Spanish record yet, so this should create a record in the _Localised table
        FluentState::singleton()->setLocale('es_ES');
        $record2->Title = 'Un archivo';
        $record2->write();

        $record3 = $this->objFromFixture(LocalisedParent::class, 'record_a');
        $this->assertTrue(
            $this->hasLocalisedRecord($record3, 'es_ES'),
            'Record Locale is set to current locale when writing new records'
        );
    }

    /**
     * Get a Locale field value directly from a record's localised database table, skipping the ORM
     *
     * @param DataObject $record
     * @param string $locale
     * @return boolean
     */
    protected function hasLocalisedRecord(DataObject $record, $locale)
    {
        $result = SQLSelect::create()
            ->setFrom($record->config()->get('table_name') . '_Localised')
            ->setWhere([
                'RecordID' => $record->ID,
                'Locale' => $locale,
            ])
            ->execute()
            ->first();

        return !empty($result);
    }

    /**
     * Ensure that records can be sorted in their locales
     *
     * @dataProvider sortRecordProvider
     * @param string $locale
     * @param string $direction
     * @param string[] $expected
     */
    public function testLocalisedFieldsCanBeSorted($locale, $direction, $expected)
    {
        FluentState::singleton()->setLocale($locale);

        $records = LocalisedParent::get()->sort('Title', $direction);
        $titles = $records->column('Title');
        $this->assertEquals($expected, $titles);
    }

    /**
     * @return array[]
     */
    public function sortRecordProvider()
    {
        return [
            'german ascending' => ['de_DE', 'ASC', ['Eine Akte', 'Lesen Sie mehr', 'Rennen']],
            'german descending' => ['de_DE', 'DESC', ['Rennen', 'Lesen Sie mehr', 'Eine Akte']],
            'english ascending' => ['en_US', 'ASC', ['A record', 'Go for a run', 'Read about things']],
            'english descending' => ['en_US', 'DESC', ['Read about things', 'Go for a run', 'A record']],
        ];
    }
}
