/*
 * YouTube Captions Auditor (YTCA)
 * Accessible table sorting by clicking on <th> elements
 *
 */

$(document).ready(function() {

  // initialize sortable table
  $('th[scope="col"]').each(function(){

    var thisHeader = $(this).text();
    var title = 'Click to sort by ' + thisHeader;
    if ($(this).is('[aria-sort="ascending"]')) {
      title += ' descending';
    }
    else {
      title += ' ascending';
    }
    $(this).attr({
      'tabindex': '0',
      'title': title
    });
  });

  // handle clicks on column headers
  $('th[scope="col"]').on('click',function() {

    var headerText, $table, tableType, colIndex, direction, $rows, switching, lastRow,
        i, j, $rowX, $rowY, cellX, cellY, shouldSwitch, switchCount = 0;

    headerText = $(this).text();

    $table = $(this).closest('table');

    if ($table.is('.summary')) {
      tableType = 'summary';
    }
    else {
      tableType = 'details';
    }

    // get or define the direction of sort
    if ($(this).is('[aria-sort]')) {
      // direction for this <th> is already set
      direction = $(this).attr('aria-sort');
    }
    else {
      // set the default direction for a newly clicked <th>
      direction = 'ascending';
    }

    // remove previous aria-sort attribute
    $('th[aria-sort]').removeAttr('aria-sort');

    // get the index of the clicked column header
    colIndex = $(this).index();

    switching = true;
    // Loop until there is no nore switching to do
    while (switching) {
      switching = false;
      $rows = $table.find('tr');
      // Loop through all table rows
      // ... except the first, which contains table headers
      // ... and the last (in the summary table), which contains totals
      if (tableType == 'summary') {
        lastRow = $rows.length - 2;
      }
      else {
        lastRow = $rows.length - 1;
      }
      for (i = 1; i < lastRow; i++) {
        shouldSwitch = false;
        // Get this row and the next row, for comparison
        j = i + 1;
        $rowX = $rows.eq(i);
        $rowY = $rows.eq(j);
        // Check to see if the two rows should switch places
        // Remove contents so comma-formatted numeric values can be compared
        cellX = $rowX.find('th, td').eq(colIndex).text().toLowerCase().replace(/,/g,'');
        cellY = $rowY.find('th, td').eq(colIndex).text().toLowerCase().replace(/,/g,'');
        if ($.isNumeric(cellX) && $.isNumeric(cellY)) {
          // these are numeric values; need to convert to integers before sorting
          cellX = parseInt(cellX);
          cellY = parseInt(cellY);
        }
        if (direction == "ascending") {
          if (cellX > cellY) {
            shouldSwitch = true;
            break; // from for loop
          }
        }
        else if (direction == "descending") {
          if (cellX < cellY) {
            shouldSwitch = true;
            break; // from for loop
          }
        }
      } // end for loop
      if (shouldSwitch) {
        // If a switch has been marked, make the switch
        // and mark that a switch has been done:
        $rowY.insertBefore($rowX);
        switching = true;
        //Each time a switch is done, increase this count by 1:
        switchCount++;
      }
      else {
        // If no switching has been done, switch directions and run the while loop again
        if (switchCount == 0) {
          if (direction == 'ascending') {
            direction = 'descending';
          }
          else {
            direction = 'ascending';
          }
          switching = true;
        }
      }
    }

    // Add aria-sort to the th of the newly sorted column
    $(this).attr('aria-sort',direction);

    // Update title
    if (direction == 'ascending') {
      $(this).attr('title','Click to sort by ' + headerText + ' descending');
    }
    else {
      $(this).attr('title','Click to sort by ' + headerText + ' ascending');
    }

  });

  // trigger click event with enter and space
  $('th[scope="col"]').on('keypress',function (e) {
    var key = e.which;
    if(key == 13 || key == 32) { // enter or space
      $(this).click();
      return false;
    }
  });
});
