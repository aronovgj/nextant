<?php

/**
 * Nextcloud - nextant
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright Maxence Lange 2016
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Nextant\Service;

use \OCA\Bookmarks\Controller\Lib\Bookmarks;
use \OCA\Nextant\Items\ItemDocument;
use Symfony\Component\Console\Helper\ProgressBar;

class IndexService
{

    const GETALL_ROWS = 100;

    const PROGRESS_TEMPLATE = "%job:1s%%message:-40s%%current:5s%/%max:5s% [%bar%] %percent:3s%% \n    %infos:1s% %jvm:-30s%      ";

    const REFRESH_INFO_SYSTEM = 5;

    private $solrService;

    private $solrTools;

    private $fileService;

    private $bookmarkService;

    private $miscService;

    private $parent;

    private $output;

    private $lastProgressTick;

    public function __construct($fileService, $bookmarkService, $solrService, $solrTools, $miscService)
    {
        $this->fileService = $fileService;
        $this->bookmarkService = $bookmarkService;
        
        $this->solrService = $solrService;
        $this->solrTools = $solrTools;
        
        $this->miscService = $miscService;
        
        $this->parent = null;
        $this->output = null;
    }

    public function setDebug($debug)
    {
        $this->miscService->setDebug($debug);
    }

    public function setParent($parent)
    {
        $this->parent = $parent;
    }

    public function setOutput($output)
    {
        $this->output = $output;
    }

    public function message($line)
    {
        if ($this->output != null)
            $this->output->writeln($line);
    }

    /**
     * Extract whatever is sent ($data)
     *
     * @param string $type            
     * @param string $userId            
     * @param array $data            
     * @return array
     */
    public function extract($type, $userId, &$data, &$solrDocs, $extract = true)
    {
        $this->solrService->setOwner($userId);
        
        if ($solrDocs == null || $solrDocs == '')
            $solrDocs = $this->getAllDocuments($type, $userId, $error);
        
        $progress = null;
        if ($this->output != null)
            $progress = new ProgressBar($this->output, sizeof($data));
        
        if ($progress != null) {
            $progress->setMessage($type . '/<info>' . $userId . '</info>: ');
            $progress->setMessage('', 'jvm');
            $progress->setMessage('', 'job');
            $progress->setMessage('', 'infos');
            $progress->setFormat(self::PROGRESS_TEMPLATE);
            $progress->start();
        }
        
        $forceExtract = false;
        foreach ($data as $entry) {
            
            if ($this->parent != null)
                $this->parent->interrupted();
            
            if ($progress != null) {
                $progress->setMessage('/', 'job');
                $progress->setMessage('[scanning]', 'infos');
                
                if ((time() - self::REFRESH_INFO_SYSTEM) > $this->lastProgressTick) {
                    $infoSystem = $this->solrTools->getInfoSystem();
                    $progress->setMessage('Solr memory: ' . $infoSystem->jvm->memory->used, 'jvm');
                    $this->lastProgressTick = time();
                }
                $progress->advance();
            }
            
            if ($entry->getType() == ItemDocument::TYPE_FILE)
                $this->fileService->syncDocument($entry);
            if ($entry->getType() == ItemDocument::TYPE_BOOKMARK)
                $this->bookmarkService->syncDocument($entry);
            
            if (! $extract)
                continue;
                
                //
                // $doc = ItemDocument::getItem($allDocs, $type, $entry->getId());
                // $needed = $this->solrTools->updateDocument($entry, $doc, false);
                //
            
            if (! $forceExtract && $this->solrTools->isDocumentUpToDate($type, $entry, ItemDocument::getItem($solrDocs, $type, $entry->getId())))
                continue;
            
            if ($progress != null) {
                $progress->setMessage('+', 'job');
                $progress->setMessage('[extracting]', 'infos');
                $progress->display();
            }
            
            if (! $this->solrService->extractDocument($entry, $error))
                continue;
            
            // $entry->processed(true);
        }
        
        if ($progress != null) {
            $progress->setMessage('', 'jvm');
            $progress->setMessage('', 'infos');
            $progress->finish();
        }
        
        return true;
    }

    public function updateDocuments($type, $userId, &$data, &$solrDocs = null)
    {
        $this->solrService->setOwner($userId);
        if (sizeof($data) > 0 && ! $data[0]->isSynced())
            $this->extract($type, $userId, $data, $solrDocs, false);
        if ($solrDocs == null || $solrDocs == '')
            $solrDocs = $this->getAllDocuments($type, $userId, $error);
        
        $progress = null;
        if ($this->output != null)
            $progress = new ProgressBar($this->output, sizeof($data));
        
        if ($progress != null) {
            $progress->setMessage($type . '/<info>' . $userId . '</info>: ');
            $progress->setMessage('', 'jvm');
            $progress->setMessage('/', 'job');
            $progress->setMessage('[comparing]', 'infos');
            $progress->setFormat(self::PROGRESS_TEMPLATE);
            $progress->start();
        }
        
        $forceExtract = false;
        foreach ($data as $entry) {
            
            if ($this->parent != null)
                $this->parent->interrupted();
            
            if ($progress != null) {
                if ((time() - self::REFRESH_INFO_SYSTEM) > $this->lastProgressTick) {
                    $infoSystem = $this->solrTools->getInfoSystem();
                    $progress->setMessage('Solr memory: ' . $infoSystem->jvm->memory->used, 'jvm');
                    $this->lastProgressTick = time();
                }
                $progress->advance();
            }
            
            $doc = ItemDocument::getItem($solrDocs, $type, $entry->getId());
            $continue = false;
            $needed = $this->solrTools->updateDocument($entry, $doc, false);
            
            if ($progress != null) {
                if (! $needed) {
                    $progress->setMessage('/', 'job');
                    $progress->setMessage('[comparing]', 'infos');
                } else {
                    $progress->setMessage('!', 'job');
                    $progress->setMessage('[updating]', 'infos');
                }
                $progress->display();
            }
            
            if ($needed)
                $this->solrTools->updateDocument($entry, $doc);
        }
        
        if ($progress != null) {
            $progress->setMessage('', 'jvm');
            $progress->setMessage('', 'infos');
            $progress->finish();
        }
        
        return true;
    }

    public function removeOrphans($type, $userId, &$data, &$solrDocs)
    {
        $this->solrService->setOwner($userId);
        if (sizeof($data) > 0 && ! $data[0]->isSynced())
            $this->extract($type, $userId, $data, $solrDocs, false);
        
        if ($solrDocs == null || $solrDocs == '')
            $solrDocs = $this->getAllDocuments($type, $userId, $error);
        
        $progress = null;
        if ($this->output != null)
            $progress = new ProgressBar($this->output, sizeof($data));
        
        if ($progress != null) {
            $progress->setFormat(self::PROGRESS_TEMPLATE);
            $progress->setMessage($type . '/<info>' . $userId . '</info>: ');
            $progress->setMessage('/', 'job');
            $progress->setMessage('', 'jvm');
            $progress->setMessage('[spoting orphans]', 'infos');
            $progress->start();
        }
        
        $docIds = array();
        foreach ($data as $entry)
            array_push($docIds, (int) $entry->getId());
        
        $deleting = array();
        foreach ($solrDocs as $doc) {
            
            if ($this->parent != null)
                $this->parent->interrupted();
            
            if (! in_array($doc->getId(), $docIds) && (! in_array($doc->getId(), $deleting))) {
                array_push($deleting, $doc->getId());
                $item = ItemDocument::getItem($solrDocs, $type, $doc->getId());
                $item->removed(true);
            }
            
            if ($progress != null) {
                if ((time() - self::REFRESH_INFO_SYSTEM) > $this->lastProgressTick) {
                    $infoSystem = $this->solrTools->getInfoSystem();
                    $progress->setMessage('Solr memory: ' . $infoSystem->jvm->memory->used, 'jvm');
                    $this->lastProgressTick = time();
                }
                
                $progress->advance();
            }
        }
        
        if ($progress != null) {
            $progress->setMessage('', 'jvm');
            $progress->setMessage('', 'infos');
            $progress->finish();
        }
        
        if (sizeof($deleting) > 0) {
            
            $progress = null;
            if ($this->output != null) {
                $progress = new ProgressBar($this->output, sizeof($deleting));
            }
            
            if ($progress != null) {
                $progress->setFormat(self::PROGRESS_TEMPLATE);
                $progress->setMessage($type . '/<info>' . $userId . '</info>: ');
                $progress->setMessage('-', 'job');
                $progress->setMessage('', 'jvm');
                $progress->setMessage('[deleting orphans]', 'infos');
                $progress->start();
            }
            
            foreach ($deleting as $docId) {
                
                if ($this->parent != null)
                    $this->parent->interrupted();
                
                $this->solrTools->removeDocument($type, $docId);
                
                foreach ($data as $entry) {
                    if ($entry->getId() == $docId) {
                        $entry->removed(true);
                        break;
                    }
                }
                
                if ($progress != null) {
                    if ((time() - self::REFRESH_INFO_SYSTEM) > $this->lastProgressTick) {
                        $infoSystem = $this->solrTools->getInfoSystem();
                        $progress->setMessage('Solr memory: ' . $infoSystem->jvm->memory->used, 'jvm');
                        $this->lastProgressTick = time();
                    }
                    
                    $progress->advance();
                }
            }
        }
        
        if ($progress != null) {
            $progress->setMessage('', 'jvm');
            $progress->setMessage('', 'infos');
            $progress->finish();
        }
    }

    /**
     * return ids of all documents
     *
     * @param string $type            
     * @param string $userId            
     * @param number $page            
     * @param boolean $lastpage            
     * @param number $error            
     * @return boolean
     */
    public function getAllDocuments($type = '', $userId = '', &$error = 0)
    {
        if (! $this->solrService || ! $this->solrService->configured() || ! $this->solrService->getClient())
            return false;
        
        $client = $this->solrService->getClient();
        $data = array();
        try {
            
            $progress = null;
            if ($this->output != null)
                $progress = new ProgressBar($this->output, $this->solrTools->count($type, $userId));
            
            if ($progress != null) {
                $progress->setMessage($type . '/<info>' . $userId . '</info>: ');
                $progress->setMessage('', 'jvm');
                $progress->setMessage('%', 'job');
                $progress->setMessage('[preparing]', 'infos');
                $progress->setFormat(self::PROGRESS_TEMPLATE);
                $progress->start();
            }
            
            if ($type != '')
                $type .= '_';
            
            $page = 0;
            $docIds = array();
            while (true) {
                
                if ($this->parent != null)
                    $this->parent->interrupted();
                
                $query = $client->createSelect();
                $helper = $query->getHelper();
                
                $query->setQuery('id:' . $type . '*');
                
                if ($userId != '')
                    $query->createFilterQuery('owner')->setQuery('nextant_owner:' . $helper->escapePhrase($userId));
                
                $query->addSort('id', $query::SORT_ASC);
                $query->setStart($page * self::GETALL_ROWS);
                $query->setRows(self::GETALL_ROWS);
                $query->setFields(array(
                    'id',
                    'nextant_owner',
                    'nextant_path',
                    'nextant_mtime',
                    'nextant_share',
                    'nextant_sharegroup',
                    'nextant_deleted',
                    'nextant_source'
                ));
                
                $resultset = $client->execute($query);
                
                foreach ($resultset as $document) {
                    
                    if ($this->parent != null)
                        $this->parent->interrupted();
                    
                    $doc = ItemDocument::fromCompleteId($document->id);
                    $doc->setOwner($document->nextant_owner);
                    $doc->setPath($document->nextant_path);
                    $doc->setMTime($document->nextant_mtime);
                    $doc->setShare($document->nextant_share);
                    $doc->setShareGroup($document->nextant_sharegroup);
                    $doc->deleted($document->nextant_deleted);
                    $doc->setSource($document->nextant_source);
                    
                    $tick = false;
                    if (! in_array($doc->getId(), $docIds)) {
                        array_push($docIds, $doc->getId());
                        $data[] = $doc;
                        $tick = true;
                    }
                    
                    if ($progress != null) {
                        
                        if ((time() - self::REFRESH_INFO_SYSTEM) > $this->lastProgressTick) {
                            $infoSystem = $this->solrTools->getInfoSystem();
                            $progress->setMessage('Solr memory: ' . $infoSystem->jvm->memory->used, 'jvm');
                            $this->lastProgressTick = time();
                        }
                        if ($tick)
                            $progress->advance();
                    }
                }
                
                if ((($page + 1) * self::GETALL_ROWS) >= $resultset->getNumFound())
                    break;
                
                $page ++;
            }
            
            if ($progress != null) {
                $progress->setMessage('', 'jvm');
                $progress->setMessage('', 'infos');
                $progress->finish();
            }
            
            return $data;
        } catch (\Solarium\Exception\HttpException $ehe) {
            if ($ehe->getStatusMessage() == 'OK')
                $error = SolrService::EXCEPTION_SOLRURI;
            else
                $error = SolrService::EXCEPTION_HTTPEXCEPTION;
        } catch (\Solarium\Exception $e) {
            $error = SolrService::EXCEPTION;
        }
        
        return false;
    }
}





