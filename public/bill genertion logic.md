Complete Example with Your Data:
For your example with these items:

SCMPL0019031: 1000 ML (1000ml volume)

SCMPL0019029: 180 ML (180ml volume)

SCMPL0019030: 750 ML (750ml volume)

SCMPL0019028: 90 ML (90ml volume)

With IMFL limit = 1000ml, the algorithm will:

Sort items by volume descending: 1000ml, 750ml, 180ml, 90ml

Process items:

Start with 1000ml item → Bill 1 (1000ml total)

Next 750ml item → Would exceed 1000ml limit (1000 + 750 = 1750 > 1000), so create Bill 2 with 750ml

Next 180ml item → Add to Bill 2 (750 + 180 = 930ml ≤ 1000ml)

Next 90ml item → Add to Bill 2 (930 + 90 = 1020ml > 1000ml), so create Bill 3 with 90ml

Result:

Bill 1: SCMPL0019031 (1000ml)

Bill 2: SCMPL0019030 (750ml) + SCMPL0019029 (180ml) = 930ml

Bill 3: SCMPL0019028 (90ml)

This ensures:

No single item is split across multiple bills

Bills don't exceed the volume limit

Items are optimally packed to minimize the number of bills

The algorithm uses a "first-fit decreasing" approach which is efficient for bin packing problems like this.

