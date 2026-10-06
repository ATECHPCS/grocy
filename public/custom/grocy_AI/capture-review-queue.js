(function (window)
{
	'use strict';

	function build(lines, receiptViews)
	{
		var scanById = {};
		var cards = (lines || []).slice().sort(function (a, b)
		{
			return Number(a.seq) - Number(b.seq) || Number(a.id) - Number(b.id);
		}).map(function (line)
		{
			var card = { key: 'scan:' + line.id, kind: 'scan', scanLineId: line.id, receiptKeys: [] };
			scanById[String(line.id)] = card;
			return card;
		});
		var receiptOwnerByKey = {};
		(receiptViews || []).forEach(function (view)
		{
			(view.lines || []).forEach(function (line)
			{
				var key = 'receipt:' + view.receipt.id + ':' + line.id;
				var isItem = line.kind === undefined || line.kind === 'item';
				var scanIds = [];
				if (isItem && line.paired_capture_line_id)
				{
					scanIds.push(String(line.paired_capture_line_id));
				}
				else if (isItem)
				{
					(line.allocations || []).forEach(function (allocation)
					{
						var id = String(allocation.capture_line_id);
						if (Number(allocation.active) !== 0 && allocation.capture_line_id && scanIds.indexOf(id) === -1) scanIds.push(id);
					});
				}
				scanIds.forEach(function (id)
				{
					if (scanById[id]) scanById[id].receiptKeys.push(key);
				});
				if (scanIds.length === 1 && scanById[scanIds[0]])
				{
					receiptOwnerByKey[key] = scanById[scanIds[0]].key;
				}
				else
				{
					cards.push({ key: key, kind: isItem ? 'receipt' : 'adjustment', receiptId: view.receipt.id, receiptLineId: line.id, receiptKeys: [key] });
					receiptOwnerByKey[key] = key;
				}
			});
		});
		return { cards: cards, receiptOwnerByKey: receiptOwnerByKey };
	}

	function retain(cards, previousKey, previousIndex)
	{
		if (!cards.length) return null;
		if (cards.some(function (card) { return card.key === previousKey; })) return previousKey;
		var index = Number.isFinite(previousIndex) ? Math.floor(previousIndex) : 0;
		return cards[Math.max(0, Math.min(index, cards.length - 1))].key;
	}

	window.GrocyAICaptureReviewQueue = { build: build, retain: retain };
})(window);
