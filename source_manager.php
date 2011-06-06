<?php

/***************************************************************************
 *   source_manager.php                                                    *
 *   Exodus: Source Manager                                                *
 *                                                                         *
 *   Copyright (C) 2006-2010 by Leif B. Kristensen                         *
 *   leif@solumslekt.org                                                   *
 *                                                                         *
 *   This program is free software; you can redistribute it and/or modify  *
 *   it under the terms of the GNU General Public License as published by  *
 *   the Free Software Foundation; either version 2 of the License, or     *
 *   (at your option) any later version.                                   *
 *                                                                         *
 *   This program is distributed in the hope that it will be useful,       *
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of        *
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         *
 *   GNU General Public License for more details.                          *
 *                                                                         *
 *   You should have received a copy of the GNU General Public License     *
 *   along with this program; if not, write to the                         *
 *   Free Software Foundation, Inc.,                                       *
 *   59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.             *
 ***************************************************************************/

/*
This script will display events, relations, and subnodes associated with each
node in the source tree. It has evolved to become the central point of the
application besides the family view. I don't think that any other genealogy
app has anything like it.
*/

// *****************************************************************************
//                    functions used only in this module
// *****************************************************************************

function node_details($e, $r, $s, $u) {
// shorthand summary for number of events, relations, subnodes / unused
// subnodes connected to this node
    $str = " ($e-$r-$s";
    if ($u)
        $str .= "/$u";
    $str .= ")";
    return $str;
}

// *****************************************************************************
//                               initialization
// *****************************************************************************

require "./settings/settings.php";
require_once "./langs/$language.php";
require "./functions.php";

// we'll display only raw dates here
pg_query("SET DATESTYLE TO GERMAN");

// $self = this node, default node is root
$self = isset($_GET['node']) ? $_GET['node'] : 0;

// set $self as active source
set_last_selected_source($self);

// get all attributes of $self in one query
$props = fetch_row_assoc("
    SELECT
        ecc($self) AS number_of_events,
        rcc($self) AS number_of_relations,
        ssc($self) AS number_of_subsources,
        usc($self) AS number_of_unused_subsources,
        get_source_text($self) AS source_txt,
        get_prev_page($self) AS prev_page,
        get_next_page($self) AS next_page,
        link_expand(source_text) AS node_txt,
        parent_id,
        sort_order,
        source_date,
        part_type,
        part_desc(part_type) AS part_desc
    FROM
        sources
    WHERE
        source_id = $self
");

$title_prev = get_source_plain_text($props['prev_page']);
$title_next = get_source_plain_text($props['next_page']);

// $title = "&#x16A2; K:$self " . get_source_plain_text($self);
$title = "K:$self " . get_source_plain_text($self);
// limit title tag to a sensible length
if (mb_strlen($title) > 80)
    $title = mb_substr($title, 0, 80) . '...';

// var used by header.php to display context dependent menu items
$source_manager = true;

require "./header.php";

// *****************************************************************************
//                                   main
// *****************************************************************************

echo "<div class=\"normal\">\n";
echo "<h2>Node $self"
    . node_details(
        $props['number_of_events'],
        $props['number_of_relations'],
        $props['number_of_subsources'],
        $props['number_of_unused_subsources']
    )
    . conc($props['part_desc'], ': ');
if ($principal = get_source_principal($self))
    echo conc($props['source_date'])
        . conc(get_name_and_lifespan($principal), ' av ');
echo "</h2>\n";

// full source text
echo para(
    square_brace($props['sort_order'])
    . conc(square_brace($props['source_date']))
    . conc($props['source_txt'])
    . conc(paren(
        to_url('./forms/source_edit.php',
            array(
                'person'    => 0,
                'source'    => $self,
                'self'      => 1
            ), $_edit))
        )
    );

// displays feedback from add_source depending on outcome; (not i18n'd yet)
// see ddl/functions.sql function add_source() for details
$new = isset($_GET['new']) ? $_GET['new'] : 0;
if ($new && $new < 0) {
    $new = abs($new);
    echo '<p class="alert">Kilden finnes allerede, se nr. ['
        . to_url($_SERVER['PHP_SELF'], array('node' => $new), $new)
        . "]!</p>\n";
}

// *****************************************************************************
// experimental section: print list of persons mentioned in this source
// *****************************************************************************

if ($props['part_type'] == 1) {
    if (fetch_val("
    	    SELECT COUNT(*) FROM source_linkage WHERE source_fk=$self")) {
        echo "<h3>Personer nevnt i kilden:</h3>\n";
        list_mentioned($self, 1);
    }
    else
        echo para(to_url('./forms/linkage_add.php',
                    array('node' => $self), 'Legg til lenke'));
}

// *****************************************************************************
// section I: print list of events cited by this source
// *****************************************************************************

if ($props['number_of_events']) {
    echo "<h3>$_Events:</h3>\n<ol>";
    $handle = pg_query("
        SELECT
            e.event_id,
            get_tag_name(e.tag_fk) AS event_name,
            e.event_date,
            get_place_name(e.place_fk) AS event_place,
            link_expand(e.event_note) AS event_note,
            get_event_type(e.event_id) AS event_type
        FROM
            events e,
            event_citations s
        WHERE
            e.event_id = s.event_fk
        AND
            s.source_fk = $self
        ORDER BY
            get_event_type(e.event_id),
            e.sort_date,
            e.event_id
    ");
    while ($row = pg_fetch_assoc($handle)) {
        $event = $row['event_id'];
        echo '<li>';
        echo square_brace($event)
            . conc(italic($row['event_name']))
            . conc(fuzzydate($row['event_date']))
            . conc($row['event_place']);
        if ($row['event_type'] < 3)
            // event has one or two participants, print names inline
            echo conc(list_participants($event), ': ');
        echo conc($row['event_note'], ': ');
        echo ' ' .
            paren(
            to_url('./forms/source_event_edit.php',
                    array(
                        'event'     => $event,
                        'source'    => $self
                    ), $_edit)
            . ' / '
            . to_url('./forms/citation_delete.php',
                    array(
                        'person'    => 0,
                        'event'     => $event,
                        'source'    => $self
                    ), $_delete)
            );
        if ($row['event_type'] == 3) {
            // event has any number of participants, print names as ordered list
            $subhandle = pg_query("
                SELECT
                    person_fk,
                    is_principal,
                    sort_order
                FROM
                    participants
                WHERE
                    event_fk=$event
                ORDER BY
                    sort_order
            ");
            echo '<ol>';
            while ($subrow = pg_fetch_assoc($subhandle)) {
                $participant = $subrow['person_fk'];
                $bp = $subrow['is_principal'] == 't' ? 'H ' : 'B ';
                echo '<li>' . $bp . linked_name($participant, './family.php');
                // a non-principal, eg a person mentioned as heir in a probate,
                // who may or may not be described in a separate note
                if ($subrow['is_principal'] == 'f') {
                    // print participant note if it exists
                    if ($note = fetch_val("
                        SELECT link_expand(part_note)
                        FROM participant_notes
                        WHERE person_fk = $participant
                        AND event_fk = $event
                    "))
                        echo ': ' . $note;
                    // print link to edit participant note
                    echo ' ' . paren(
                        to_url('./forms/part_note.php',
                            array(
                                'person'    => $participant,
                                'event'     => $event,
                                'node'      => $self
                            ), $_edit)
                        );
                }
                echo "</li>\n";
            }
            echo "</ol>\n";
        }
        echo "</li>\n";
    }
    echo "</ol>\n";
}

// *****************************************************************************
// section II: print list of relations cited by this source
// *****************************************************************************

if ($props['number_of_relations']) {
    $child[1] = $_son;
    $child[2] = $_daughter;
    echo "<h3>$_Relations:</h3>\n<ol>";
    $handle = pg_query("
        SELECT
            r.relation_id,
            r.parent_fk,
            r.child_fk,
            get_lsurety(r.surety_fk) AS surety
        FROM
            relations r,
            relation_citations c
        WHERE
            c.relation_fk = r.relation_id
        AND
            c.source_fk = $self
        ORDER BY
            get_pbdate(r.child_fk),
            r.child_fk,
            get_gender(r.parent_fk)
    ");
    while ($row = pg_fetch_assoc($handle)) {
        echo li(linked_name($row['child_fk'], './family.php')
            . " $_is " . $row['surety'] . ' '
            . $child[get_gender($row['child_fk'])] . " $_of "
            . linked_name($row['parent_fk'], './family.php')
        );
    }
    echo "</ol>\n";
}

// *****************************************************************************
// section III: print list of subsources
// *****************************************************************************

if ($props['number_of_subsources']) {
    echo "<h3>$_Subsources:</h3>\n";
    echo "<table>";
    $handle = pg_query("
        SELECT
            source_id,
            link_expand(source_text) AS txt,
            sort_order,
            source_date,
            ecc(source_id) AS e,
            rcc(source_id) AS r,
            ssc(source_id) AS s,
            usc(source_id) AS u,
            get_part_type_string(part_type) AS pt
        FROM
            sources
        WHERE
            parent_id = $self
        AND
            source_id <> 0
        ORDER BY
            sort_order,
            source_date,
            source_text
    ");
    while ($row = pg_fetch_assoc($handle)) {
        $id = $row['source_id'];
        echo '<tr>';
        echo td_numeric(square_brace(to_url($_SERVER['PHP_SELF'],
                                        array('node' => $id), $id, $_goto)));
        echo td_numeric(square_brace($row['sort_order']));
        echo td(paren(to_url('./forms/source_edit.php',
                array(
                    'person'    => 0,
                    'source'    => $id
                ), $row['pt'], $_edit)));
        if ($row['e'] || $row['r'] || $row['s']) {
            echo td(square_brace(italic($row['source_date']))
                . ' ' . $row['txt']
                . node_details($row['e'], $row['r'], $row['s'], $row['u']));
        }
        else { // source is unused, print with gray text
            if ($show_delete) { // show link for source deletion
                echo td(span_type(square_brace(italic($row['source_date']))
                    . conc($row['txt']),"faded")
                    . conc(paren(to_url('./forms/source_delete.php',
                                array(
                                    'node'  => $self,
                                    'id'    => $id
                                ), bold($_delete)))));
            }
            else
                echo td(span_type(square_brace(italic($row['source_date']))
                    . conc($row['txt']), "faded"));
        }
        echo "</tr>\n";
    }
    echo "</table>\n";
}

// extra add source link to avoid scrolling
echo para(to_url('./forms/source_add.php',
    array('node' => $self), $_Add_source));

echo "</div>\n";

include "./footer.php";
?>
