-- The switch on the node-side lease fence (MAIN <-> LB API plan, section 9).
-- Off by default: a node keeps serving whatever its lease says until an operator
-- turns this on, and it must be on before the licence it guards lapses (the
-- replica section that carries it to a node is a granting record).
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `lb_lease_fence` tinyint(1) DEFAULT '0';
