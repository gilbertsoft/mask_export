<?php
namespace CPSIT\MaskExport\Aggregate;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Nicole Cordes <typo3@cordes.co>, CPS-IT GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @package mask
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class TtContentOverridesAggregate extends AbstractOverridesAggregate
{
    /**
     * @var string
     */
    protected $table = 'tt_content';

    /**
     * Adds necessary TCA override information for tt_content table
     */
    protected function process()
    {
        if (empty($GLOBALS['TCA'][$this->table])
            || empty($this->maskConfiguration[$this->table]['tca']) && empty($this->maskConfiguration[$this->table]['elements'])
        ) {
            return;
        }

        $tableConfiguration = $GLOBALS['TCA'][$this->table];
        $this->addTableColumns($tableConfiguration);
        $this->addTableTypes($tableConfiguration);
        $this->addContentElementsRendering();
    }

    /**
     * @param array $tableConfiguration
     */
    protected function addTableTypes(array $tableConfiguration)
    {
        $types = array_keys($this->maskConfiguration[$this->table]['elements']);
        $newTypeFields = array_intersect_key(
            $tableConfiguration['types'],
            array_combine(
                array_map(function ($value) {
                    return 'mask_' . $value;
                }, $types),
                $types
            )
        );

        if (empty($newTypeFields)) {
            return;
        }

        ksort($newTypeFields);

        foreach ($newTypeFields as $type => $_) {
            $this->addLabel(
                $this->languageFilePath . $this->languageFileIdentifier,
                $this->table. '.CType.' . $type,
                $this->maskConfiguration[$this->table]['elements'][substr($type, 5)]['label']
            );
            $this->appendPhpFile(
                $this->tcaOverridesFilePath . $this->table . '.php',
<<<EOS
\$GLOBALS['TCA']['{$this->table}']['columns']['CType']['config']['items'][] = array(
    'LLL:EXT:mask/{$this->languageFilePath}{$this->languageFileIdentifier}:{$this->table}.CType.{$type}',
    '{$type}',
);

EOS
            );
        }

        $tempTypes = var_export($newTypeFields, true);
        $this->appendPhpFile(
            $this->tcaOverridesFilePath . $this->table . '.php',
<<<EOS
\$tempTypes = {$tempTypes};
\$GLOBALS['TCA']['{$this->table}']['types'] += \$tempTypes;

EOS
        );
    }

    protected function addContentElementsRendering()
    {
        if (empty($this->maskConfiguration[$this->table]['elements'])) {
            return;
        }

        $this->addPlainTextFile(
            $this->typoScriptFilePath . 'constants.ts',
            ''
        );
        $this->addPlainTextFile(
            $this->typoScriptFilePath . 'setup.ts',
            ''
        );
        $this->appendPhpFile(
            $this->tcaOverridesFilePath . $this->table . '.php',
<<<EOS
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'mask',
    '{$this->typoScriptFilePath}',
    'mask'
);

EOS
        );

        foreach ($this->maskConfiguration[$this->table]['elements'] as $element) {
            $this->addTypoScript($element);
            $this->addFluidTemplate($element);
        }
    }

    /**
     * @param array $element
     */
    protected function addTypoScript(array $element)
    {
        $templatesPath = 'EXT:mask/' . $this->templatesFilePath . GeneralUtility::underscoredToUpperCamelCase($this->table);
        $key = $element['key'];
        $this->appendPlainTextFile(
            $this->typoScriptFilePath . 'setup.ts',
<<<EOS
tt_content.mask_{$key} = FLUIDTEMPLATE
tt_content.mask_{$key} {
    file = {$templatesPath}/{$key}.html

EOS
        );

        $index = 10;
        $dataProcessing = '';
        foreach ($element['columns'] as $columnName) {
            if (empty($GLOBALS['TCA']['tt_content']['columns'][$columnName]['config']['foreign_table'])
            ) {
                continue;
            }

            switch ($GLOBALS['TCA']['tt_content']['columns'][$columnName]['config']['foreign_table']) {
                case 'sys_file_reference':
                    $dataProcessing .= $this->addFileProcessorForField($columnName, $index);
                    break;
                case 'tt_content':
                    $dataProcessing .= $this->addDatabaseQueryProcessorForField($columnName, $index);
                    break;
            }
            $index += 10;
        }
        if (!empty($dataProcessing)) {
            $this->appendPlainTextFile($this->typoScriptFilePath . 'setup.ts', $dataProcessing);
        }

        $this->appendPlainTextFile(
            $this->typoScriptFilePath . 'setup.ts',
<<<EOS
}

EOS
        );
    }

    /**
     * @param string $columnName
     * @param int $index
     * @return string
     */
    protected function addFileProcessorForField($columnName, $index)
    {
        $index = (int)$index;
        return
<<<EOS
    dataProcessing.{$index} = TYPO3\CMS\Frontend\DataProcessing\FilesProcessor
    dataProcessing.{$index} {
        if.isTrue.field = {$columnName}
        references {
            fieldName = {$columnName}
            table = tt_content
        }
        as = {$columnName}
    }

EOS;
    }

    /**
     * @param string $columnName
     * @param int $index
     * @return string
     */
    protected function addDatabaseQueryProcessorForField($columnName, $index)
    {
        $index = (int)$index;
        $where = '1=1';
        if (!empty($GLOBALS['TCA']['tt_content']['columns'][$columnName]['config']['foreign_record_defaults'])) {
            foreach ($GLOBALS['TCA']['tt_content']['columns'][$columnName]['config']['foreign_record_defaults'] as $key => $value) {
                $where .= ' AND ' . $key . '=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($value, 'tt_content');
            }
        }

        return
<<<EOS
    dataProcessing.{$index} = TYPO3\CMS\Frontend\DataProcessing\DatabaseQueryProcessor
    dataProcessing.{$index} {
        if.isTrue.field = {$columnName}
        table = tt_content
        pidInList.field = pid
        where = {$columnName}_parent=###uid### AND deleted=0 AND hidden=0 AND {$where}
        markers {
            uid.field = uid
        }
        as = {$columnName}
    }

EOS;
    }
}
