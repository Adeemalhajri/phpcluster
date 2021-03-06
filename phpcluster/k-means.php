<?php
/**
 *  K-means implementation.
 *
 *  PHP Version 5
 *
 *  @category Text
 *  @package  PHPClustering
 *  @author   C�sar Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt PHP License 3.01
 *  @link     http://cesar.la/cluster
 */

require_once dirname(__FILE__)."/base.php";


/**
 *  This class imeplements K-means clustering algorithm.
 *
 *  @category Text
 *  @package  PHPClustering
 *  @author   C�sar Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt PHP License 3.01
 *  @link     http://cesar.la/cluster
 */
class Kmeans extends Cluster_base
{
    private $_centroid    = 100;
    protected $threshold  = 0.5;
    protected $recentroid = 3;
    protected $centcent   = 0.05;

    // {{{ setThreshold
    /**
     *  Centroid members minimum score threshold. 
     *  
     *  The threshold value must be > 1 and < 100, greatest
     *  values means better results and more iterations.
     *  
     *  The default value is 50%.
     *
     *  @param integer $threshold Threshold value.
     *
     *  @return bool
     */
    final function setThreshold($threshold)
    {
        if ($threshold > 100 or $threshold < 1) {
            return false;
        }
        $this->threshold = $threshold/100;
        return true;
    }
    // }}}

    // {{{ setCentroids
    /**
     *  Set the number of centroids.
     *
     *  The centroid value is the number of clusters
     *  or groups where the documents will be grouped. 
     *
     *  This value need to be less than half of number
     *  of documents to categorize.
     *
     *  @param int $number Number of centroids.
     *
     *  @return bool
     */
    final function setCentroids($number)
    {
        if (!is_integer($number)) {
            return false;
        }
        $this->_centroid = $number;
        return true;
    }
    // }}} 

    // setReCentroidNumber {{{
    /**
     *  Re-centroid
     *
     *  The re-centroid is an operation introduced in commit 
     *  e0fb305d0404231b07e005460828ffb76b87f7b5 that basically 
     *  improves the result by replacing those empty centroids by 
     *  some documents with no-centroids (due our threshold).
     *
     *  This function sets the maximum iteration to perform this 
     *  operation. A high number slow down the computation time but
     *  improves the result.
     * 
     *  @param int $int Maximum number of iterations to perform this task.
     *
     *  @return void
     */
    final function setReCentroidNumber($int) {
        $this->centcent = $int / 100;
    }
    // }}}


    // {{{ _narray
    /**
     *  Auxiliar function to generar N arrays of arrays/
     *
     *  @param int $ncur Number of elements.
     *
     *  @return array
     */
    final private function _narray($ncur)
    {
        $arr = array();
        for ($i=0; $i < $ncur; $i++) {
            $arr[$i] = array();
        }
        return $arr;
    }
    // }}} 

    /* clusterCenters {{{ */
    /**
     *  Cluster Centers
     * /* 
     *  Create a brif cluster over the centroids, it really speed up the
     *  computation time if there's a great number of centroids.
     *
     *  @param array $centroids Current centroids
     *  @param array &$centers  Output variables for centroid's cluster.
     *
     *  @return void
     */
    final function clusterCenters($centroids, &$centers)
    {
        $number  = count($centroids);
        $ncent   = ceil($this->centcent * $number);
        $cents   = array();
        $centers = array();

        if ($number <= 30) {
            die("here"); 
            return;    
        }

        $tmp = array();
        for ($i=0; $i < $ncent; $i++) {
            do {
                $id = rand(0, $number-1);
            } while (isset($tmp[$id]) || is_null($centroids[$id]) );
            $tmp[$id] = true;
            $cents[]  = $centroids[ $id ];
        }

        $result = array();
        for ($i=0; $i < $number; $i++) {
            $bmatch_val = 10;
            $row = & $centroids[ $i ];
            if ($row == null) {
                continue;
            }
            for ($e=0; $e < $ncent; $e++) {
                $d = $this->distance( $cents[$e], $row);
                if ($d < $bmatch_val) {
                    $bmatch     = $e;
                    $bmatch_val = $d;
                }
            }
            $result[$bmatch][] = $i;
        }
        unset($cents);

        for ($i=0; $i < $ncent; $i++) {
            $center = & $centers[$i];
            $avg    = & $center->features;

            $center->members  = array();
            if (!isset($result[$i])) {
                $center = null;
                continue;
            }
            $nnodes = count($result[$i]);
            foreach ($result[$i] as $id) {
                $centroids[$id]->cid = $id; 
                $this->featuresMerge($avg, $centroids[$id]->features);
                $center->members[]  = & $centroids[$id];
            }

            foreach (array_keys($avg) as $wid) {
                $avg[$wid] = ceil($avg[$wid] / $nnodes);
                if ($avg[$wid] > 2) {
                    /* deleting those very popular items from centroids */
                    unset($avg[$wid]);
                }
            }
            
            $this->distanceInit($center);
        }
        
    }
    /* }}} */

    //  mainCluster  {{{ 
    /**
     *  The K-means algorithm implementation.
     *
     *  K-means clustering is a method of cluster analysis which 
     *  aims to partition n observations into k clusters in which 
     *  each observation belongs to the cluster with the nearest mean. 
     *
     *  It is similar to the expectation-maximization algorithm for mixtures
     *  of Gaussians in that they both attempt to find the centers of 
     *  natural clusters in the data.
     *  
     *  @param int   $iteration Maximum number of iterations.
     *  @param array &$node     Array of objects to categorize
     *
     *  @return array Array of centroids and elements IDs.
     */
    final protected function mainCluster($iteration,&$node)
    {   
        $max       = count($node);
        $threshold = 1 - $this->threshold;
        $ncentroid = $this->_centroid;
        $centroid  = array();
        /* list of random nodes selected as centroids */
        /* with no similar node */
        $blacklist = array();

        /* initialize first centroids  {{{ */
        $temp = array();
        if ($ncentroid > ($max/3)) {
            $ncentroid = ceil($max/3) -1;
        }

        for ($i=0; $i < $ncentroid; $i++) {
            do {
                $id = rand(0, $max-1);
            } while (isset($temp[$id]));
            $temp [$id] = true;
            $centroid[] = $node[$id];
        }
        unset($temp);
        /* }}} */

        /* main loop */
        $oldmatches = array();
        for ($ite = 0; $ite < $iteration; $ite++) {
            $this->doLog("Iteration $ite");
            $bmatches = $this->_narray($ncentroid);
            $centers  = array();
            $this->clusterCenters($centroid, $centers);
            /* Candidates nodes for new centroids */
            $centCand = array();

            /* find a centroid for every element {{{*/
            for ($i=0; $i < $max; $i++) {
                $row = & $node[$i];

                /* group centroids by similarity {{{ */
                $bmatch_val = 10;
                for ($e = 0; $e < count($centers); $e++) {
                    if ($centers[$e]===null) continue;
                    $d = $this->distance($centers[$e], $row);
                    if ($d < $bmatch_val) {
                        $pzCent     = $e;
                        $bmatch_val = $d;
                    }
                }

                $bmatch_val = 10;
                //krsort($pzCent);
                /* }}} */
                //print_r(array($pzCent, array_slice($pzCent, 0, 2, true)));

                //foreach(array_slice($pzCent, 0, 1, true) as $key => $score) {

                $_center = & $centers[$pzCent]->members;
                $_count  = count($_center);

                for ($e = 0; $e < $_count; $e++) {
                    $d = $this->distance($_center[$e], $row);
                    if ($d < $bmatch_val) {
                        $bmatch     = $_center[$e]->cid;
                        $bmatch_val = $d;
                    }
                }
                
                //}

                /* we just want good results, that's why there */
                /* is a threshold                              */
                if ($bmatch_val < $threshold) {
                    $bmatches[$bmatch][] = $i;
                    $xmatches[$bmatch][] = $bmatch_val;
                } else if ($ite < $this->recentroid && !isset($blacklist[$node[$i]->id])) {
                   /* we collect very differents nodes as candidates to fit */
                   /* empty centroids spaces                                */
                   $centCand[] = $i; 
                }
            }
            /* }}} */

            if ($this->iterationDiff($oldmatches, $bmatches) > 99) {
                break;
            }
            $this->centroidsMerge($centroid, $bmatches, $node, $centCand);
            $oldmatches = $bmatches;
        }

        /* now from out $bmatches get the key */
        /* put into an array and return it {{{  */
        $clusters = array();
        for ($i=0; $i < $ncentroid; $i++) {
            if ($bmatches[$i] === null || count($bmatches[$i]) == 1) {
                continue;
            }
            $cluster  = array();
            $elements = & $bmatches[$i];
            $nelement = count($elements);
            for ($e=0; $e < $nelement; $e++) {
                $id           = $node[$elements[$e]]->id;
                $cluster[$id] = array($this->text[$id], $xmatches[$i][$e]);
            }
            if (count($cluster)==0) {
                continue;
            }
            $clusters[] = $cluster;
        }
        /*  }}} */

        return $clusters;
    }
    // }}}

    /* features Merge {{{ */
    /**
     *  Features Merge
     *
     *  This function merges all the features and returns the features
     *  for the centroid.
     *
     *  @param array &$array  Target array.
     *  @param array &$array1 Features.
     *
     *  @return void
     */
    protected function featuresMerge(&$array, &$array1)
    {
        foreach ($array1 as $key => $value) {
            if (!isset($array[$key])) {
                $array[$key] = 0;
            }
            $array[$key] += $value;
        }
    }
    /* }}} */

    // merge all the features per centroid {{{ 
    /**
     *  Centroids Merge
     *
     *  This function creates new centroids based on all items. Basically it mergers
     *  all entrie's features, and also deletes empty centroids and reasign new centroids
     *  if it possible (in order to get better results).
     *
     *  @param array $&centroid Centroids.
     *  @param array $&bmatches Array with all items and centroid.
     *  @param array &$node     All items
     *  @param array &$centCand Array with all items that qualifies as possible centroids.
     *
     *  @return void
     */
    protected function centroidsMerge(&$centroid, &$bmatches, &$node, &$centCand) {
        $ncCand = count($centCand); 
        $ncands = 0;
        $cands  = array();
        $free   = 0;

        $ncentroid = count($centroid);

        for ($i=0; $i < $ncentroid; $i++) {
            $nnodes = count($bmatches[$i]);

            /* empty centroid or with a single similar node {{{ */
            if ($nnodes <= 1) {
                /*
                ** putting the centroid element (which in this case is the same as 
                ** centroid itself) in the black list  
                */
                if ($centroid[$i] !== null) {
                    $blacklist[ $centroid[$i]->id ] = true;
                }
                /* we have got an empty  centroids, let's try to fetch some */
                /* other centroid                                           */
                if ($ncCand > 0 && $ncCand > $ncands) {
                    do {
                        $rnd = rand(0, $ncCand-1);
                    } while ( isset($cands[$rnd]) );
                    $newcenter = $node[ $centCand[ $rnd ] ];
                    $centroid[$i] = $newcenter;
                    $ncands++;
                } else { 
                    /* empty centroid or only one match*/
                    $centroid[$i] = null;
                    $free++;
                }
                continue;
            } 
            /* }}} */

            /* merging all features in every node */
            $wcount = array();
            $avg    = array();
            for ($e=0; $e < $nnodes; $e++) {
                $nid = $bmatches[$i][$e];
                $this->featuresMerge($avg, $node[$nid]->features);
            }

            /* saving only the average */
            foreach (array_keys($avg) as $wid) {
                $avg[$wid] = ceil($avg[$wid] / $nnodes);
                if ($avg[$wid] > 2) {
                    /* deleting those very popular items from centroids */
                    unset($avg[$wid]);
                }
            }

            if (count($avg) == 0) {
                $centroid[$i] =  null;
                continue;
            }

            /* add the new centroid value and prepare */
            $centroid[$i]->features = $avg;
            $this->distanceInit($centroid[$i]);
        }
        $this->doLog("\t$ncands New centroids.");
        $this->doLog("\t$free free clusters.");
    }
    // }}}

    // iterationDiff {{{
    /**
     *  Iteration Diff.
     *
     *  Performs an iteration result's diff and return
     *  it similarity. 
     *
     *  @param array $result1 Iteration result
     *  @param array $result2 Iteration result
     *
     *  @return float Similarity (0..100)
     */
    protected function iterationDiff($result1, $result2)
    {
        $total = count($result2);
        $eq    = 0;
        foreach ($result1 as $key => $value) {
            if (count($value) < 2) {
                $value = null;
            }
            if (count($result2[$key]) < 2) {
                $result2[$key] = null;
            }
            if ($result2[$key] == $value) {
                $eq++;
            }
        }
        return $eq/$total * 100;
    }
    // }}}

}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
?>
