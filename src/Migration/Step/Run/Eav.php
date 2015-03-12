<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\Run;

use Migration\MapReader;
use Migration\ProgressBar;
use Migration\Resource\Destination;
use Migration\Resource\Record;
use Migration\Resource\Document;
use Migration\Resource\RecordFactory;
use Migration\Resource\Source;
use Migration\Step\Eav\Helper;
use Migration\Step\Eav\InitialData;

/**
 * Class Eav
 */
class Eav
{
    /**
     * @var array;
     */
    protected $newAttributes;

    /**
     * @var array;
     */
    protected $newAttributeSets;

    /**
     * @var array;
     */
    protected $newAttributeGroups;

    /**
     * @var array;
     */
    protected $destAttributeOldNewMap;

    /**
     * @var array;
     */
    protected $destAttributeSetsOldNewMap;

    /**
     * @var array;
     */
    protected $destAttributeGroupsOldNewMap;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var MapReader
     */
    protected $map;

    /**
     * @var RecordFactory
     */
    protected $factory;

    /**
     * @var InitialData
     */
    protected $initialData;

    /**
     * @var ProgressBar
     */
    protected $progress;

    /**
     * @param Source $source
     * @param Destination $destination
     * @param MapReader $mapReader
     * @param Helper $helper
     * @param RecordFactory $factory
     * @param InitialData $initialData
     * @param ProgressBar $progress
     */
    public function __construct(
        Source $source,
        Destination $destination,
        MapReader $mapReader,
        Helper $helper,
        RecordFactory $factory,
        InitialData $initialData,
        ProgressBar $progress
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->map = $mapReader;
        $this->helper = $helper;
        $this->factory = $factory;
        $this->initialData = $initialData;
        $this->progress = $progress;
    }

    /**
     * Entry point. Run migration of EAV structure.
     * @return void
     */
    public function perform()
    {
        $this->progress->start($this->getIterationsCount());
        $this->migrateAttributeSetsAndGroups();
        $this->migrateAttributes();
        $this->migrateEntityAttributes();
        $this->migrateMappedTables();
        $this->migrateJustCopyTables();
        $this->progress->finish();
    }

    /**
     * Migrate eav_attribute_set and eav_attribute_group
     * @return void
     */
    protected function migrateAttributeSetsAndGroups()
    {
        foreach (['eav_attribute_set', 'eav_attribute_group'] as $documentName) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapReader::TYPE_SOURCE)
            );

            $sourceRecords = $this->source->getRecords($documentName, 0, $this->source->getRecordsCount($documentName));
            $recordsToSave = $destinationDocument->getRecords();
            foreach ($sourceRecords as $recordData) {
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
                $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                    ->transform($sourceRecord, $destinationRecord);
                $recordsToSave->addRecord($destinationRecord);
            }

            if ($documentName == 'eav_attribute_set') {
                foreach ($this->initialData->getAttributeSets('dest') as $record) {
                    $record['attribute_set_id'] = null;
                    $destinationRecord = $this->factory->create(
                        [
                            'document' => $destinationDocument,
                            'data' => $record
                        ]
                    );
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            if ($documentName == 'eav_attribute_group') {
                foreach ($this->initialData->getAttributeGroups('dest') as $record) {
                    $oldAttributeSet = $this->initialData->getAttributeSets('dest')[$record['attribute_set_id']];
                    $newAttributeSet = $this->newAttributeSets[
                        $oldAttributeSet['entity_type_id'] . '-' . $oldAttributeSet['attribute_set_name']
                    ];
                    $record['attribute_set_id'] = $newAttributeSet['attribute_set_id'];

                    $record['attribute_group_id'] = null;
                    $destinationRecord = $this->factory->create(
                        [
                            'document' => $destinationDocument,
                            'data' => $record
                        ]
                    );
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            $this->saveRecords($destinationDocument, $recordsToSave);
            if ($documentName == 'eav_attribute_set') {
                $this->loadNewAttributeSets();
            }
            if ($documentName == 'eav_attribute_group') {
                $this->loadNewAttributeGroups();
            }
        }
    }

    /**
     * Migrate eav_attribute
     * @return void
     */
    protected function migrateAttributes()
    {
        $this->progress->advance();
        $sourceDocName = 'eav_attribute';
        $sourceDocument = $this->source->getDocument($sourceDocName);
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($sourceDocName, MapReader::TYPE_SOURCE)
        );

        $sourceRecords = $this->source->getRecords($sourceDocName, 0, $this->source->getRecordsCount($sourceDocName));
        $destinationRecords = $this->initialData->getAttributes('dest');

        $recordsToSave = $destinationDocument->getRecords();
        foreach ($sourceRecords as $sourceRecordData) {
            /** @var Record $sourceRecord */
            $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $sourceRecordData]);
            /** @var Record $destinationRecord */
            $destinationRecord = $this->factory->create(['document' => $destinationDocument]);

            $mappingValue = $this->getMappingValue($sourceRecord, ['entity_type_id', 'attribute_code']);
            if (isset($destinationRecords[$mappingValue])) {
                $destinationRecordData = $destinationRecords[$mappingValue];
                unset($destinationRecords[$mappingValue]);
            } else {
                $destinationRecordData = array_fill_keys($destinationRecord->getFields(), null);
            }
            $destinationRecord->setData($destinationRecordData);

            $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                ->transform($sourceRecord, $destinationRecord);
            $recordsToSave->addRecord($destinationRecord);
        }

        foreach ($destinationRecords as $record) {
            /** @var Record $destinationRecord */
            $destinationRecord = $this->factory->create(['document' => $destinationDocument, 'data' => $record]);
            $destinationRecord->setValue('attribute_id', null);
            $recordsToSave->addRecord($destinationRecord);
        }

        $this->saveRecords($destinationDocument, $recordsToSave);
        $this->loadNewAttributes();
    }

    /**
     * Migrate eav_entity_attributes
     *
     * @return void
     */
    protected function migrateEntityAttributes()
    {
        $this->progress->advance();
        $sourceDocName = 'eav_entity_attribute';
        $sourceDocument = $this->source->getDocument($sourceDocName);
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($sourceDocName, MapReader::TYPE_SOURCE)
        );

        $recordsToSave = $destinationDocument->getRecords();
        foreach ($this->helper->getSourceRecords($sourceDocName) as $sourceRecordData) {
            $sourceRecord = $this->factory->create([
                'document' => $sourceDocument,
                'data' => $sourceRecordData
            ]);
            $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
            $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                ->transform($sourceRecord, $destinationRecord);
            $recordsToSave->addRecord($destinationRecord);
        }

        foreach ($this->helper->getDestinationRecords('eav_entity_attribute') as $record) {
            if (!isset($this->destAttributeOldNewMap[$record['attribute_id']])
                || !isset($this->destAttributeSetsOldNewMap[$record['attribute_set_id']])
                || !isset($this->destAttributeGroupsOldNewMap[$record['attribute_group_id']])
            ) {
                continue;
            }
            $record['attribute_id'] = $this->destAttributeOldNewMap[$record['attribute_id']];
            $record['attribute_set_id'] = $this->destAttributeSetsOldNewMap[$record['attribute_set_id']];
            $record['attribute_group_id'] = $this->destAttributeGroupsOldNewMap[$record['attribute_group_id']];

            $record['entity_attribute_id'] = null;
            $destinationRecord = $this->factory->create(['document' => $destinationDocument, 'data' => $record]);
            $recordsToSave->addRecord($destinationRecord);
        }

        $this->saveRecords($destinationDocument, $recordsToSave);
    }

    /**
     * Migrate EAV tables which in result must have all unique records from both suorce and destination documents
     * @return void
     */
    protected function migrateMappedTables()
    {
        $documents = [
            'catalog_eav_attribute' => ['attribute_id'],
            'customer_eav_attribute' => ['attribute_id'],
            'eav_entity_type' => ['entity_type_id'],
            'enterprise_rma_item_eav_attribute' => ['attribute_id'],
        ];

        foreach ($documents as $documentName => $mappingFields) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapReader::TYPE_SOURCE)
            );

            $destinationRecords = $this->helper->getDestinationRecords($documentName, $mappingFields);
            $recordsToSave = $destinationDocument->getRecords();
            foreach ($this->helper->getSourceRecords($documentName) as $recordData) {
                /** @var Record $sourceRecord */
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                /** @var Record $destinationRecord */
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);

                if ($mappingFields) {
                    $mappingValue = $this->getMappingValue($sourceRecord, $mappingFields);
                    if (isset($destinationRecords[$mappingValue])) {
                        $destinationRecordData = $destinationRecords[$mappingValue];
                        unset($destinationRecords[$mappingValue]);
                    } else {
                        $destinationRecordData = array_fill_keys($destinationRecord->getFields(), null);
                    }
                    $destinationRecord->setData($destinationRecordData);
                }

                $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                    ->transform($sourceRecord, $destinationRecord);
                $recordsToSave->addRecord($destinationRecord);
            }

            if ($mappingFields) {
                foreach ($destinationRecords as $record) {
                    $destinationRecord = $this->factory->create([
                        'document' => $destinationDocument,
                        'data' => $record
                    ]);
                    if (isset($record['attribute_id'])
                        && isset($this->destAttributeOldNewMap[$record['attribute_id']])
                    ) {
                        $destinationRecord->setValue(
                            'attribute_id',
                            $this->destAttributeOldNewMap[$record['attribute_id']]
                        );
                    }
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            $this->saveRecords($destinationDocument, $recordsToSave);
        }
    }

    /**
     * Migrate tables which does not require some custom migration logic
     * @throws \Exception
     * @return void
     */
    protected function migrateJustCopyTables()
    {
        foreach ($this->helper->getJustCopyDocuments() as $documentName) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapReader::TYPE_SOURCE)
            );

            $sourceRecords = $this->helper->getSourceRecords($documentName);
            $recordsToSave = $destinationDocument->getRecords();
            foreach ($sourceRecords as $recordData) {
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
                $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                   ->transform($sourceRecord, $destinationRecord);
                $recordsToSave->addRecord($destinationRecord);
            }
            $this->saveRecords($destinationDocument, $recordsToSave);
        }
    }

    /**
     * @param Document $document
     * @param Record\Collection $recordsToSave
     * @return void
     */
    protected function saveRecords(Document $document, Record\Collection $recordsToSave)
    {
        $this->destination->clearDocument($document->getName());
        $this->destination->saveRecords($document->getName(), $recordsToSave);
    }

    /**
     * @param Record $sourceRecord
     * @param array $keyFields
     * @return string
     */
    protected function getMappingValue(Record $sourceRecord, $keyFields)
    {
        $value = [];
        foreach ($keyFields as $field) {
            switch ($field) {
                case 'attribute_id':
                    $value[] =  $this->getDestinationAttributeId($sourceRecord->getValue($field));
                    break;
                default:
                    $value[] = $sourceRecord->getValue($field);
                    break;
            }
        }
        return implode('-', $value);
    }

    /**
     * Load migrated attribute sets data
     * @return void
     */
    protected function loadNewAttributeSets()
    {
        $this->newAttributeSets = $this->helper->getDestinationRecords(
            'eav_attribute_set',
            ['entity_type_id', 'attribute_set_name']
        );
        foreach ($this->initialData->getAttributeSets('dest') as $attributeSetId => $record) {
            $newAttributeSet = $this->newAttributeSets[$record['entity_type_id'] . '-' . $record['attribute_set_name']];
            $this->destAttributeSetsOldNewMap[$attributeSetId] = $newAttributeSet['attribute_set_id'];
        }
    }

    /**
     * Load migrated attribute groups data
     * @return void
     */
    protected function loadNewAttributeGroups()
    {
        $this->newAttributeGroups = $this->helper->getDestinationRecords(
            'eav_attribute_group',
            ['attribute_set_id', 'attribute_group_name']
        );
        foreach ($this->initialData->getAttributeGroups('dest') as $record) {
            $newKey = $this->destAttributeSetsOldNewMap[$record['attribute_set_id']] . '-'
                . $record['attribute_group_name'];
            $newAttributeGroup = $this->newAttributeGroups[$newKey];
            $this->destAttributeGroupsOldNewMap[
                $record['attribute_group_id']] = $newAttributeGroup['attribute_group_id'
            ];
        }
    }

    /**
     * Load migrated attributes data
     * @return array
     */
    protected function loadNewAttributes()
    {
        $this->newAttributes = $this->helper->getDestinationRecords(
            'eav_attribute',
            ['entity_type_id', 'attribute_code']
        );
        foreach ($this->initialData->getAttributes('dest') as $key => $attributeData) {
            $this->destAttributeOldNewMap[$attributeData['attribute_id']] = $this->newAttributes[$key]['attribute_id'];
        }

        return $this->newAttributes;
    }

    /**
     * @param int $sourceAttributeId
     * @return mixed
     */
    protected function getDestinationAttributeId($sourceAttributeId)
    {
        $id = null;
        $key = null;
        if (isset($this->initialData->getAttributes('source')[$sourceAttributeId])) {
            $key = $this->initialData->getAttributes('source')[$sourceAttributeId]['entity_type_id'] . '-'
                . $this->initialData->getAttributes('source')[$sourceAttributeId]['attribute_code'];
        }

        if ($key && isset($this->initialData->getAttributes('dest')[$key])) {
            $id = $this->initialData->getAttributes('dest')[$key]['attribute_id'];
        }

        return $id;
    }

    /**
     * @return int
     */
    public function getIterationsCount()
    {
        return count($this->helper->getDocumentsMap());
    }
}