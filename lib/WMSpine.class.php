<?php

// TODO - switch all this "array of mixed types" stuff to use an array of WMSpineElements

/**
 * Class WMSpineElement - a single item in a spine.
 *
 * Previously this was an array with a WMPoint and a number. This
 * is nicer to read, and actually works properly with type inference.
 */
class WMSpineElement
{
    /** @var  WMPoint $point */
    public $point;
    /** @var  float $distance */
    public $distance;

    public function __construct($point, $distance)
    {
        $this->point = $point;
        $this->distance = $distance;
    }
}

/**
 * Class WMSpineSearchResult - A 'struct' effectively for the results of the Spine search functions.
 *
 * Previously an array of misc. This is easier to read and helps type inference.
 */
class WMSpineSearchResult
{
    /** @var  WMPoint $point */
    public $point;
    /** @var  float $distance */
    public $distance;
    /** @var float $angle */
    public $angle;
    /** @var int $index */
    public $index;
}

class WMSpine
{
    /** @var  WMSpineElement[] $elements */
    private $elements;

    /**
     * Add a raw spine entry, assuming it's correct - used for copying spines around
     *
     * @param array $newEntry
     */
//    function addRawEntry($newEntry)
//    {
//        $this->addRawElement(new WMSpineElement($newEntry[SPINE_POINT], $newEntry[SPINE_DISTANCE]));
//    }

    /**
     * Add a WMSpineElement as-as, assuming the distance inside is correct
     * (used for copying spines around)
     *
     * @param WMSpineElement $newElement
     */
    private function addRawElement($newElement)
    {
        $this->elements[] = $newElement;
    }

    /**
     * Add a point to the end of the spine, calculating the new distance
     *
     * @param WMPoint $newPoint
     */
    public function addPoint($newPoint)
    {
        if (is_null($this->elements)) {
            $this->elements = array();
            $distance = 0;
        } else {
            $lastElement = end($this->elements);

            reset($this->elements);

            $lastDistance = $lastElement->distance;
            $lastPoint = $lastElement->point;

            $distance = $lastDistance + $lastPoint->distanceToPoint($newPoint);
        }

        $this->addRawElement(new WMSpineElement($newPoint, $distance));
    }

    public function pointCount()
    {
        return count($this->elements);
    }

    /**
     * @param int $index
     * @return WMPoint
     */
    public function getPoint($index)
    {
        return $this->elements[$index]->point;
    }

    /**
     * @return float
     */
    public function totalDistance()
    {
        $lastElement = end($this->elements);
        reset($this->elements);

        return $lastElement->distance;
    }

    public function simplify($epsilon = 1e-10)
    {
        $output = new WMSpine();

        $output->addPoint($this->elements[0]->point);
        $maxStartIndex = count($this->elements) - 2;
        $skip = 0;

        for ($n = 1; $n <= $maxStartIndex; $n++) {
            // figure out the area of the triangle formed by this point, and the one before and after
            $area = getTriangleArea(
                $this->elements[$n - 1]->point,
                $this->elements[$n]->point,
                $this->elements[$n + 1]->point
            );

            if ($area > $epsilon) {
                $output->addPoint($this->elements[$n]->point);
            } else {
                // ignore n
                $skip++;
            }
        }

        wm_debug("Skipped $skip points of $maxStartIndex\n");

        $output->addPoint($this->elements[$maxStartIndex + 1]->point);

        return $output;
    }

//    function firstPoint()
//    {
//        return $this->elements[0]->point;
//    }

    public function lastPoint()
    {
        return $this->elements[$this->pointCount() - 1]->point;
    }

    // find the tangent of the spine at a given index (used by DrawComments)
    public function findTangentAtIndex($index)
    {
        $maxIndex = $this->pointCount() - 1;

        if ($index <= 0) {
            // if we're at the start, always use the first two points
            $index = 0;
        }

        if ($index >= $maxIndex) {
            // if we're at the end, always use the last two points
            $index = $maxIndex - 1;
        }

        // just a regular point on the spine
        $point1 = $this->elements[$index]->point;
        $point2 = $this->elements[$index + 1]->point;

        $tangent = $point1->vectorToPoint($point2);
        $tangent->normalise();

        return $tangent;
    }

    public function findPointAtDistance($targetDistance)
    {
        // We find the nearest lower point for each distance,
        // then linearly interpolate to get a more accurate point
        // this saves having quite so many points-per-curve
        if (count($this->elements) === 0) {
            throw new WeathermapInternalFail("Called findPointAtDistance with an empty WMSpline");
        }

        $foundIndex = $this->findIndexNearDistance($targetDistance);

        // Figure out how far the target distance is between the found point and the next one
        $ratio = ($targetDistance - $this->elements[$foundIndex]->distance) / ($this->elements[$foundIndex + 1]->distance - $this->elements[$foundIndex]->distance);

        // linearly interpolate x and y to get to the actual required distance
        $newPoint = $this->elements[$foundIndex]->point->LERPWith($this->elements[$foundIndex + 1]->point, $ratio);

        return array($newPoint, $foundIndex);
    }

    public function findPointAndAngleAtPercentageDistance($targetPercentage)
    {
        $targetDistance = $this->totalDistance() * ($targetPercentage / 100);

        // find the point and angle
        $result = $this->findPointAndAngleAtDistance($targetDistance);
        // append the distance we calculated, in case it's needed by the caller
        // (e.g. arrowhead calcs are part percentage (splitpos) and part absolute (arrrowsize))
        $result[] = $targetDistance;

        return $result;
    }

    public function findPointAndAngleAtDistance($targetDistance)
    {
        // This is the point we need
        list($point, $index) = $this->findPointAtDistance($targetDistance);

        // now to find one either side of it, to get a line to find the angle of
        $left = $index;
        $right = $left + 1;
        $max = count($this->elements) - 1;
        // if we're right up against the last point, then step backwards one
        if ($right > $max) {
            $left--;
            $right--;
        }

        $pointLeft = $this->elements[$left]->point;
        $pointRight = $this->elements[$right]->point;

        $vec = $pointLeft->vectorToPoint($pointRight);
        $angle = $vec->getAngle();

        return array($point, $index, $angle);
    }

    /**
     * findIndexNearDistance
     *
     * return the index of the point either at (unlikely) or just before the target distance
     * we will linearly interpolate afterwards to get a true point
     *
     * @param $targetDistance
     * @return int - index of the point found
     * @throws WeathermapInternalFail
     */
    public function findIndexNearDistance($targetDistance)
    {
        $left = 0;
        $right = count($this->elements) - 1;

        if ($left == $right) {
            return $left;
        }

        // if the distance is zero, there's no need to search (and it doesn't work anyway)
        if ($targetDistance == 0) {
            return $left;
        }

        // if it's a point past the end of the line, then just return the end of the line
        // Weathermap should *never* ask for this, anyway
        if ($this->elements[$right]->distance < $targetDistance) {
            return $right;
        }

        // if it's a point before the start of the line, then just return the start of the line
        // Weathermap should *never* ask for this, anyway, either
        if ($targetDistance < 0) {
            return $left;
        }

        // if somehow we have a 0-length curve, then don't try and search, just give up
        // in a somewhat predictable manner
        if ($this->elements[$left]->distance == $this->elements[$right]->distance) {
            return $left;
        }

        while ($left <= $right) {
            $mid = intval(floor(($left + $right) / 2));

            if (($this->elements[$mid]->distance <= $targetDistance) && ($this->elements[$mid + 1]->distance > $targetDistance)) {
                return $mid;
            }

            if ($targetDistance <= $this->elements[$mid]->distance) {
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }

        throw new WeathermapInternalFail("Howie's crappy binary search is wrong after all.\n");
    }

    /** split - split the Spine into two new spines, with splitIndex in the first one
     *  used by the link-drawing code to make one curve into two arrows
     *
     * @param int $splitIndex
     *
     * @returns WMSpine[] two new spines either side of the split
     */
    private function split($splitIndex)
    {
        $spine1 = new WMSpine();
        $spine2 = new WMSpine();

        $endCursor = $this->pointCount() - 1;
        $totalDistance = $this->totalDistance();

        for ($i = 0; $i < $splitIndex; $i++) {
            $spine1->addRawElement(clone $this->elements[$i]);
        }

        // work backwards from the end, finishing with the same point
        // Recalculate the distance from the other end as we go
        for ($i = $endCursor; $i > $splitIndex; $i--) {
            $newElement = clone $this->elements[$i];
            //     wm_debug("  $totalDistance => $newDistance  \n");
            $newElement->distance = $totalDistance - $this->elements[$i]->distance;
            $spine2->addRawElement($newElement);
        }

        return array($spine1, $spine2);
    }

    public function splitAtDistance($splitDistance)
    {
        list($halfwayPoint, $halfwayIndex) = $this->findPointAtDistance($splitDistance);

        wm_debug($this . "\n");
        wm_debug("Halfway split (%d) is at index %d %s\n", $splitDistance, $halfwayIndex, $halfwayPoint);

        list($spine1, $spine2) = $this->split($halfwayIndex);

        // Add the actual midpoint back to the end of both spines (on the reverse one, reverse the distance)
        $spine1->addRawElement(new WMSpineElement($halfwayPoint, $splitDistance));
        $spine2->addRawElement(new WMSpineElement($halfwayPoint, $this->totalDistance() - $splitDistance));

        wm_debug($spine1 . "\n");
        wm_debug($spine2 . "\n");

        return array($spine1, $spine2);
    }

    public function __toString()
    {
        $output = "SPINE:[";
        for ($i = 0; $i < $this->pointCount(); $i++) {
            $output .= sprintf("%s[%s]--", $this->elements[$i]->point, $this->elements[$i]->distance);
        }
        $output .= "]";

        return $output;
    }

    public function drawSpine($gdImage, $colour)
    {
        $nPoints = count($this->elements) - 1;

        for ($i = 0; $i < $nPoints; $i++) {
            $point1 = $this->elements[$i]->point;
            $point2 = $this->elements[$i + 1]->point;
            imageline(
                $gdImage,
                $point1->x,
                $point1->y,
                $point2->x,
                $point2->y,
                $colour
            );
        }
    }

    public function drawChain($gdImage, $colour, $size = 10)
    {
        $nPoints = count($this->elements);

        for ($i = 0; $i < $nPoints; $i++) {
            imagearc(
                $gdImage,
                $this->elements[$i]->point->x,
                $this->elements[$i]->point->y,
                $size,
                $size,
                0,
                360,
                $colour
            );
        }
    }
}
